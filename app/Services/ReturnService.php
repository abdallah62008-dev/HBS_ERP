<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\Refund;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Manages the lifecycle of an order return.
 *
 * Lifecycle (Phase 3 — Received is an optional checkpoint):
 *   Pending → [Received] → Inspect → (Restocked | Damaged) → Closed
 *
 * Two equivalent legal paths to a verdict:
 *   - Fast path:     Pending → inspect()           → (Restocked|Damaged) → close()
 *   - Received path: Pending → markReceived() →
 *                              inspect()           → (Restocked|Damaged) → close()
 *
 * Choosing one over the other is operational: warehouses that batch-
 * inspect at end of shift will use the Received checkpoint to log "the
 * parcel is on the receive bay, inspection pending"; warehouses that
 * inspect on the spot keep the fast path. Behaviour after inspect() is
 * identical on both paths.
 *
 * Key effects:
 *   - markReceived(): NO inventory / refund / cashbox effect.
 *     Pure lifecycle marker.
 *   - inspect(): records condition, decides restockable, stamps inspector,
 *     and either KEEPS the order's prior `Return To Stock` movement
 *     (Good + restockable) or REVERSES it (any other verdict). Writes the
 *     inventory_movement reversal row referenced to the OrderReturn.
 *
 * The cancel-marketer-profit and update-customer-risk steps from the
 * spec land in Phase 5 (marketer wallet) and Phase 6 (smart alerts).
 */
class ReturnService
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly CustomerRiskService $riskService,
    ) {}

    /**
     * @param  array{
     *   order_id:int,
     *   return_reason_id:int,
     *   product_condition?:string,
     *   refund_amount?:float,
     *   shipping_loss_amount?:float,
     *   notes?:string,
     * }  $payload
     */
    public function open(array $payload): OrderReturn
    {
        return DB::transaction(function () use ($payload) {
            $order = Order::with('items')->findOrFail($payload['order_id']);

            $return = OrderReturn::create([
                'order_id' => $order->id,
                'customer_id' => $order->customer_id,
                'shipping_company_id' => $order->activeShipment?->shipping_company_id,
                'return_reason_id' => $payload['return_reason_id'],
                'product_condition' => $payload['product_condition'] ?? 'Unknown',
                'return_status' => 'Pending',
                'refund_amount' => $payload['refund_amount'] ?? 0,
                'shipping_loss_amount' => $payload['shipping_loss_amount'] ?? 0,
                'notes' => $payload['notes'] ?? null,
                'created_by' => Auth::id(),
                'updated_by' => Auth::id(),
            ]);

            AuditLogService::logModelChange($return, 'created', 'returns');

            return $return;
        });
    }

    /**
     * Inspect a return: classifies the goods and applies the correct
     * inventory movement. If the order was already moved to `Returned`
     * via OrderService, that wrote a `Return To Stock` movement
     * optimistically — when the inspector decides "Damaged", we have to
     * reverse it so on-hand reflects reality.
     */
    public function inspect(
        OrderReturn $return,
        string $condition,
        bool $restockable,
        ?float $refundAmount = null,
        ?string $notes = null,
    ): OrderReturn {
        if (! in_array($condition, OrderReturn::CONDITIONS, true)) {
            throw new RuntimeException("Unknown product condition: {$condition}");
        }

        return DB::transaction(function () use ($return, $condition, $restockable, $refundAmount, $notes) {
            $return->loadMissing('order.items');
            $order = $return->order;

            // If already inspected, refuse — make the operator open a new return.
            if (! in_array($return->return_status, ['Pending', 'Received'], true)) {
                throw new RuntimeException('Return already inspected. Open a new return record for further changes.');
            }

            $warehouse = $this->inventory->defaultWarehouse();
            if (! $warehouse) {
                throw new RuntimeException('No active warehouse configured.');
            }

            // OrderService wrote an optimistic Return To Stock on the
            // post-ship → Returned transition. The inspection verdict
            // either keeps it (Good + restockable) or reverses it (Damaged
            // / Missing Parts / Unknown / non-restockable).
            //
            // We deliberately do NOT also write a Return Damaged movement.
            // The original Ship -qty already removed the goods from
            // sellable on-hand; a separate Return Damaged -qty would
            // double-decrement. The damage write-off is captured by the
            // returns row (product_condition='Damaged', return_status='Damaged')
            // and the audit_logs entry — no additional inventory_movements
            // row is required for that.
            foreach ($order->items as $item) {
                if ($condition === 'Good' && $restockable) {
                    // Optimistic Return To Stock stays — nothing to record.
                    continue;
                }

                $this->inventory->record(
                    productId: $item->product_id,
                    variantId: $item->product_variant_id,
                    warehouseId: $warehouse->id,
                    movementType: 'Return To Stock',
                    signedQuantity: -(int) $item->quantity,
                    referenceType: OrderReturn::class,
                    referenceId: $return->id,
                    notes: 'Reversal — return inspected as ' . $condition,
                );
            }

            $return->forceFill([
                'product_condition' => $condition,
                'restockable' => $restockable,
                'return_status' => $restockable ? 'Restocked' : 'Damaged',
                'refund_amount' => $refundAmount ?? $return->refund_amount,
                'notes' => $notes ?? $return->notes,
                'inspected_by' => Auth::id(),
                'inspected_at' => now(),
                'updated_by' => Auth::id(),
            ])->save();

            // Customer risk recalculation — a returned order is a negative
            // signal in CustomerRiskService.
            if ($order->customer) {
                $this->riskService->refreshFor($order->customer);
            }

            AuditLogService::log(
                action: 'inspected',
                module: 'returns',
                recordType: OrderReturn::class,
                recordId: $return->id,
                newValues: [
                    'condition' => $condition,
                    'restockable' => $restockable,
                    'refund_amount' => $return->refund_amount,
                ],
            );

            return $return->refresh();
        });
    }

    /**
     * Limited details edit for an existing return — back-office correction
     * path for the three NON-state-machine fields.
     *
     * Allowed fields:
     *   - refund_amount
     *   - shipping_loss_amount
     *   - notes
     *
     * Forbidden fields (every other key in $data is silently ignored at
     * the service layer — defence-in-depth on top of the controller's
     * validate() whitelist):
     *   - return_status   (lifecycle state — change via inspect/close)
     *   - product_condition / restockable (inventory side-effects — inspect)
     *   - inspected_*, closed_*, created_by  (system-managed)
     *   - order_id, customer_id, return_reason_id  (would re-link the return)
     *
     * Guards:
     *   - return.canBeEdited() (status !== 'Closed')
     *   - refund_amount cannot drop below the sum of linked active refunds
     *     (`Refund::ACTIVE_STATUSES`, mirroring the Phase 5C over-return
     *     guard in reverse).
     *
     * No order status change. No refund creation. No cashbox writes.
     *
     * @param  array{refund_amount?: numeric, shipping_loss_amount?: numeric, notes?: ?string}  $data
     */
    public function updateDetails(OrderReturn $return, array $data, ?User $user = null): OrderReturn
    {
        if (in_array($return->return_status, ['Closed'], true)) {
            throw new RuntimeException(
                "Return #{$return->id} is closed and cannot be edited."
            );
        }

        $actor = $user ?? Auth::user();

        return DB::transaction(function () use ($return, $data, $actor) {
            $locked = OrderReturn::query()->lockForUpdate()->findOrFail($return->id);

            if ($locked->return_status === 'Closed') {
                throw new RuntimeException(
                    "Return #{$locked->id} is closed and cannot be edited."
                );
            }

            $old = [
                'refund_amount' => (string) $locked->refund_amount,
                'shipping_loss_amount' => (string) $locked->shipping_loss_amount,
                'notes' => $locked->notes,
            ];

            $patch = [];

            if (array_key_exists('refund_amount', $data) && $data['refund_amount'] !== null && $data['refund_amount'] !== '') {
                $newAmount = round((float) $data['refund_amount'], 2);
                if ($newAmount < 0) {
                    throw new InvalidArgumentException('Refund amount cannot be negative.');
                }

                // Cannot drop refund_amount below the sum of active refunds
                // already linked to this return. Active = requested | approved | paid.
                $activeRefundTotal = (float) Refund::query()
                    ->where('order_return_id', $locked->id)
                    ->whereIn('status', Refund::ACTIVE_STATUSES)
                    ->sum('amount');

                if ($newAmount < $activeRefundTotal) {
                    throw new InvalidArgumentException(
                        "Refund amount ({$newAmount}) cannot be reduced below the cumulative active refunds "
                        . "({$activeRefundTotal}) already linked to this return."
                    );
                }

                $patch['refund_amount'] = $newAmount;
            }

            if (array_key_exists('shipping_loss_amount', $data) && $data['shipping_loss_amount'] !== null && $data['shipping_loss_amount'] !== '') {
                $newShipping = round((float) $data['shipping_loss_amount'], 2);
                if ($newShipping < 0) {
                    throw new InvalidArgumentException('Shipping loss amount cannot be negative.');
                }
                $patch['shipping_loss_amount'] = $newShipping;
            }

            if (array_key_exists('notes', $data)) {
                $patch['notes'] = $data['notes'] !== null ? (string) $data['notes'] : null;
            }

            if (empty($patch)) {
                // Nothing changed — short-circuit, don't audit a no-op.
                return $locked->refresh();
            }

            $patch['updated_by'] = $actor?->id;
            $locked->forceFill($patch)->save();

            AuditLogService::log(
                action: 'updated',
                module: 'returns',
                recordType: OrderReturn::class,
                recordId: $locked->id,
                oldValues: $old,
                newValues: [
                    'refund_amount' => isset($patch['refund_amount']) ? (string) $patch['refund_amount'] : $old['refund_amount'],
                    'shipping_loss_amount' => isset($patch['shipping_loss_amount']) ? (string) $patch['shipping_loss_amount'] : $old['shipping_loss_amount'],
                    'notes' => array_key_exists('notes', $patch) ? $patch['notes'] : $old['notes'],
                ],
            );

            return $locked->refresh();
        });
    }

    /**
     * Phase 3 — optional Received checkpoint.
     *
     * Transitions a return from `Pending` to `Received` to record that the
     * warehouse has physically received the parcel but has not yet
     * inspected it. This is an **optional** step — the legacy
     * `Pending → inspect()` fast-path remains supported (inspect() still
     * accepts both `Pending` and `Received` as input states).
     *
     * Critical no-side-effect contract:
     *   - NO inventory movement is written.
     *   - NO refund or cashbox row is created or touched.
     *   - product_condition, refund_amount, shipping_loss_amount, notes —
     *     all untouched.
     *
     * The inventory side-effect for a return still happens exactly twice:
     *   1. at `Order → Returned` (the optimistic +qty restock), and
     *   2. at `inspect()` if the verdict is non-restockable (the reversal).
     *
     * Receiving sits between these two and writes no inventory rows.
     */
    public function markReceived(OrderReturn $return, ?User $actor = null): OrderReturn
    {
        if ($return->return_status !== 'Pending') {
            throw new RuntimeException(
                "Return #{$return->id} cannot be marked as received from status '{$return->return_status}'. "
                . "Only returns in 'Pending' can be received."
            );
        }

        $user = $actor ?? Auth::user();

        return DB::transaction(function () use ($return, $user) {
            $locked = OrderReturn::query()->lockForUpdate()->findOrFail($return->id);

            // Re-check inside the transaction to defend against a race
            // where another operator inspected or closed between the
            // pre-tx check and the lock acquisition.
            if ($locked->return_status !== 'Pending') {
                throw new RuntimeException(
                    "Return #{$locked->id} is no longer in 'Pending' (current: '{$locked->return_status}'). "
                    . "Refresh the page to see the latest state."
                );
            }

            $old = ['return_status' => $locked->return_status];

            $locked->forceFill([
                'return_status' => 'Received',
                'updated_by' => $user?->id,
            ])->save();

            AuditLogService::log(
                action: 'received',
                module: 'returns',
                recordType: OrderReturn::class,
                recordId: $locked->id,
                oldValues: $old,
                newValues: ['return_status' => 'Received'],
            );

            return $locked->refresh();
        });
    }

    public function close(OrderReturn $return, ?string $note = null): OrderReturn
    {
        return DB::transaction(function () use ($return, $note) {
            $return->forceFill([
                'return_status' => 'Closed',
                'notes' => $note ? trim(($return->notes ?? '') . "\n[closed] " . $note) : $return->notes,
                'updated_by' => Auth::id(),
            ])->save();

            AuditLogService::log(
                action: 'closed',
                module: 'returns',
                recordType: OrderReturn::class,
                recordId: $return->id,
            );

            return $return->refresh();
        });
    }
}
