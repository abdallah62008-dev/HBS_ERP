<?php

namespace App\Services;

use App\Models\ApprovalRequest;
use App\Models\Marketer;
use App\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Central approval workflow.
 *
 * Concept:
 *   - Caller invokes `request($type, $target, $newValues, $reason)`.
 *     A row is inserted in approval_requests with status=Pending and
 *     a snapshot of old/new values.
 *   - A second user opens /approvals, reviews, and clicks Approve or
 *     Reject. ApprovalService dispatches to a handler by approval_type
 *     and executes the change inside a DB transaction.
 *
 * Adding a new approval type:
 *   1. Add the type string to ApprovalRequest::TYPES
 *   2. Add a handler method below
 *   3. Register it in HANDLERS
 *   4. Trigger from the relevant controller via `request()` instead of
 *      doing the destructive action directly
 */
class ApprovalService
{
    public function __construct(
        private readonly SmartAlertsService $alerts,
    ) {}

    /** @var array<string, string> Map type → method name on this class */
    private const HANDLERS = [
        'Delete Order' => 'executeDeleteOrder',
        'Edit Confirmed Order Price' => 'executeEditConfirmedOrderPrice',
        'Pay Marketer' => 'executePayMarketer',
    ];

    /**
     * Create a Pending approval request and notify managers.
     *
     * @param  array<string,mixed>|null  $oldValues
     * @param  array<string,mixed>|null  $newValues
     */
    public function request(
        string $type,
        ?Model $target,
        ?array $oldValues,
        ?array $newValues,
        ?string $reason = null,
    ): ApprovalRequest {
        if (! in_array($type, ApprovalRequest::TYPES, true)) {
            throw new RuntimeException("Unknown approval type: {$type}");
        }

        $req = ApprovalRequest::create([
            'requested_by' => Auth::id(),
            'approval_type' => $type,
            'related_type' => $target?->getMorphClass() ?? ($target ? $target::class : null),
            'related_id' => $target?->getKey(),
            'old_values_json' => $oldValues,
            'new_values_json' => $newValues,
            'reason' => $reason,
            'status' => 'Pending',
        ]);

        AuditLogService::log('approval_requested', 'approvals',
            ApprovalRequest::class, $req->id,
            newValues: ['type' => $type, 'reason' => $reason],
        );

        // Notify the manager queue so the bell pings.
        $shortName = $target ? class_basename($target::class) . " #" . $target->getKey() : null;
        $this->alerts->notifyApprovalNeeded(
            title: "Approval needed: {$type}" . ($shortName ? " · {$shortName}" : ''),
            message: $reason ?: 'Awaiting review.',
            actionUrl: "/approvals/{$req->id}",
        );

        return $req;
    }

    public function approve(ApprovalRequest $req, ?string $notes = null): ApprovalRequest
    {
        if (! $req->isPending()) {
            throw new RuntimeException('Request is not pending.');
        }
        if ($req->requested_by === Auth::id()) {
            throw new RuntimeException('You cannot approve your own request. Ask another team member.');
        }

        $handler = self::HANDLERS[$req->approval_type] ?? null;
        if (! $handler) {
            throw new RuntimeException("No handler registered for type: {$req->approval_type}.");
        }

        return DB::transaction(function () use ($req, $notes, $handler) {
            // Execute the action.
            $this->{$handler}($req);

            $req->forceFill([
                'status' => 'Approved',
                'reviewed_by' => Auth::id(),
                'reviewed_at' => now(),
                'review_notes' => $notes,
            ])->save();

            AuditLogService::log('approved', 'approvals',
                ApprovalRequest::class, $req->id,
                newValues: ['type' => $req->approval_type, 'related_id' => $req->related_id],
            );

            return $req->refresh();
        });
    }

    public function reject(ApprovalRequest $req, string $notes): ApprovalRequest
    {
        if (! $req->isPending()) {
            throw new RuntimeException('Request is not pending.');
        }

        $req->forceFill([
            'status' => 'Rejected',
            'reviewed_by' => Auth::id(),
            'reviewed_at' => now(),
            'review_notes' => $notes,
        ])->save();

        AuditLogService::log('rejected', 'approvals',
            ApprovalRequest::class, $req->id,
            newValues: ['notes' => $notes],
        );

        return $req->refresh();
    }

    /* ─────────────── Handlers ─────────────── */

    private function executeDeleteOrder(ApprovalRequest $req): void
    {
        $order = Order::find($req->related_id);
        if (! $order) throw new RuntimeException('Order not found.');

        $order->update(['deleted_by' => Auth::id()]);
        $order->delete();

        AuditLogService::log('soft_deleted', 'orders',
            Order::class, $order->id,
            oldValues: ['order_number' => $order->order_number],
        );
    }

    /**
     * Edit fields on a confirmed (or later) order. Allowed fields are
     * limited; the caller passes the patch in new_values_json.
     */
    private function executeEditConfirmedOrderPrice(ApprovalRequest $req): void
    {
        $order = Order::find($req->related_id);
        if (! $order) throw new RuntimeException('Order not found.');

        $patch = $req->new_values_json ?? [];
        $allowed = array_intersect_key($patch, array_flip([
            'discount_amount', 'shipping_amount', 'extra_fees', 'cod_amount',
        ]));

        $order->forceFill([
            ...$allowed,
            'updated_by' => Auth::id(),
        ])->save();

        AuditLogService::log('edit_confirmed_price', 'orders',
            Order::class, $order->id,
            oldValues: $req->old_values_json,
            newValues: $allowed,
        );
    }

    /**
     * Pay a marketer. Approval request stores amount + notes; here we
     * delegate to MarketerWalletService.
     */
    private function executePayMarketer(ApprovalRequest $req): void
    {
        $marketer = Marketer::find($req->related_id);
        if (! $marketer) throw new RuntimeException('Marketer not found.');

        $amount = (float) ($req->new_values_json['amount'] ?? 0);
        if ($amount <= 0) throw new RuntimeException('Invalid payout amount.');

        app(MarketerWalletService::class)->payout(
            marketer: $marketer,
            amount: $amount,
            notes: $req->reason ?: 'Approved payout',
        );
    }
}
