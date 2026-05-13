<?php

namespace App\Services;

use App\Models\Collection;
use App\Models\Refund;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Finance Phase 5A — Refund business logic.
 *
 * Only paperwork transitions in Phase 5A:
 *   approve(): requested → approved
 *   reject():  requested → rejected
 *
 * Neither transition writes a cashbox_transaction. Phase 5B will add
 * `pay()` and the OUT transaction; that method is intentionally absent
 * here.
 *
 * Over-refund guard (`assertRefundableAmount`) prevents the cumulative
 * `requested + approved` refunds for a single collection from exceeding
 * `collection.amount_collected`. The guard is a soft tool for
 * order-only refunds (no collection link → cannot be enforced; the
 * caller decides). This is a documented Phase 5A limitation.
 */
class RefundService
{
    public const MODULE = 'finance.refund';

    /**
     * Approve a requested refund.
     *
     * Guards (re-run inside a `lockForUpdate` transaction so two
     * concurrent approve calls cannot both pass):
     *   - status must still be `requested`
     *   - over-refund guard re-evaluated on the locked row
     */
    public function approve(Refund $refund, ?User $user = null): Refund
    {
        if (! $refund->canBeApproved()) {
            throw new RuntimeException(
                "Refund #{$refund->id} cannot be approved (status: {$refund->status})."
            );
        }

        $actor = $user ?? Auth::user();

        return DB::transaction(function () use ($refund, $actor) {
            $locked = Refund::query()->lockForUpdate()->findOrFail($refund->id);

            if (! $locked->canBeApproved()) {
                throw new RuntimeException(
                    "Refund #{$locked->id} cannot be approved (status: {$locked->status})."
                );
            }

            // Re-evaluate the over-refund guard on the locked row using
            // the refund's own amount (already stored).
            $this->assertRefundableAmount(
                excludeRefundId: $locked->id,
                collectionId: $locked->collection_id,
                proposedAmount: (float) $locked->amount,
            );

            $locked->fill([
                'status' => Refund::STATUS_APPROVED,
                'approved_by' => $actor?->id,
                'approved_at' => now(),
            ])->save();

            AuditLogService::log(
                action: 'refund_approved',
                module: self::MODULE,
                recordType: Refund::class,
                recordId: $locked->id,
                oldValues: ['status' => Refund::STATUS_REQUESTED],
                newValues: [
                    'status' => Refund::STATUS_APPROVED,
                    'approved_by' => $actor?->id,
                    'approved_at' => $locked->approved_at?->toDateTimeString(),
                ],
            );

            return $locked;
        });
    }

    /**
     * Reject a requested refund.
     */
    public function reject(Refund $refund, ?User $user = null): Refund
    {
        if (! $refund->canBeRejected()) {
            throw new RuntimeException(
                "Refund #{$refund->id} cannot be rejected (status: {$refund->status})."
            );
        }

        $actor = $user ?? Auth::user();

        return DB::transaction(function () use ($refund, $actor) {
            $locked = Refund::query()->lockForUpdate()->findOrFail($refund->id);

            if (! $locked->canBeRejected()) {
                throw new RuntimeException(
                    "Refund #{$locked->id} cannot be rejected (status: {$locked->status})."
                );
            }

            $locked->fill([
                'status' => Refund::STATUS_REJECTED,
                'rejected_by' => $actor?->id,
                'rejected_at' => now(),
            ])->save();

            AuditLogService::log(
                action: 'refund_rejected',
                module: self::MODULE,
                recordType: Refund::class,
                recordId: $locked->id,
                oldValues: ['status' => Refund::STATUS_REQUESTED],
                newValues: [
                    'status' => Refund::STATUS_REJECTED,
                    'rejected_by' => $actor?->id,
                    'rejected_at' => $locked->rejected_at?->toDateTimeString(),
                ],
            );

            return $locked;
        });
    }

    /**
     * Over-refund guard.
     *
     * Throws InvalidArgumentException if the proposed amount would
     * push the cumulative `requested + approved + paid` refunds on the
     * same collection above the collection's `amount_collected`.
     *
     * Pass `excludeRefundId` to omit the current refund from the
     * existing-sum (so updating a refund's own amount doesn't double-
     * count it).
     *
     * Order-only refunds (no `collection_id`) cannot be enforced
     * safely from this service in Phase 5A — the order's "refundable
     * base" depends on a chain of returns / cancellations / settlement
     * timing that isn't modelled yet. Phase 5A documents this as a
     * limitation; the guard simply returns for those rows.
     */
    public function assertRefundableAmount(?int $excludeRefundId, ?int $collectionId, float $proposedAmount): void
    {
        if ($proposedAmount <= 0) {
            throw new InvalidArgumentException('Refund amount must be greater than zero.');
        }

        if (! $collectionId) {
            // Order-only refund: no per-collection refundable base to
            // check against. Documented Phase 5A limitation. Allow.
            return;
        }

        $collection = Collection::find($collectionId);
        if (! $collection) {
            // Validation layer (RefundRequest exists check) should
            // catch this, but defensive: a missing collection has no
            // refundable base.
            throw new InvalidArgumentException("Collection #{$collectionId} not found.");
        }

        $base = (float) $collection->amount_collected;
        if ($base <= 0) {
            throw new InvalidArgumentException(
                "Collection #{$collectionId} has no collected amount; no refund can be issued against it."
            );
        }

        $query = Refund::query()
            ->where('collection_id', $collectionId)
            ->whereIn('status', Refund::ACTIVE_STATUSES);

        if ($excludeRefundId) {
            $query->where('id', '!=', $excludeRefundId);
        }

        $existingActive = (float) $query->sum('amount');
        $total = round($existingActive + $proposedAmount, 2);

        if ($total > $base) {
            throw new InvalidArgumentException(
                "Cumulative active refunds for collection #{$collectionId} ({$total}) "
                . "would exceed the collected amount ({$base}). "
                . "Existing active refunds total {$existingActive}; the requested amount of "
                . "{$proposedAmount} pushes it over."
            );
        }
    }
}
