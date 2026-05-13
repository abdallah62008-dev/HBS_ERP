<?php

namespace App\Services;

use App\Models\Cashbox;
use App\Models\CashboxTransaction;
use App\Models\Collection;
use App\Models\PaymentMethod;
use App\Models\Refund;
use App\Models\User;
use Carbon\Carbon;
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
     * Phase 5B — Pay an approved refund.
     *
     * Writes a single cashbox OUT transaction and stamps the refund as
     * paid. The full sequence runs inside one `DB::transaction` with
     * the same row-lock pattern proven by Phase 4.5 hardening:
     *
     *   1. lock the Refund row (block double-pay)
     *   2. lock the Cashbox row (block overdraft race)
     *   3. re-run every guard against the locked state
     *   4. write the cashbox_transactions row (source_type='refund')
     *   5. update the refund (status='paid', paid_at, paid_by,
     *      cashbox_id, payment_method_id, cashbox_transaction_id)
     *
     * Cheap checks (active cashbox, active payment method) happen
     * before the transaction so the caller gets a clean error before
     * any locks are acquired.
     *
     * @param  array{cashbox_id:int|string, payment_method_id:int|string, occurred_at?:?string}  $data
     */
    public function pay(Refund $refund, ?User $user, array $data): Refund
    {
        if (! $refund->canBePaid()) {
            throw new RuntimeException(
                "Refund #{$refund->id} cannot be paid (status: {$refund->status})."
            );
        }

        $cashbox = Cashbox::findOrFail((int) $data['cashbox_id']);
        $paymentMethod = PaymentMethod::findOrFail((int) $data['payment_method_id']);

        // Cheap, non-racy checks outside the transaction.
        if (! $cashbox->is_active) {
            throw new RuntimeException("Cashbox \"{$cashbox->name}\" is inactive.");
        }
        if (! $paymentMethod->is_active) {
            throw new RuntimeException("Payment method \"{$paymentMethod->name}\" is inactive.");
        }

        $defaultOccurredAt = ! empty($data['occurred_at'])
            ? Carbon::parse($data['occurred_at'])
            : null;

        $actor = $user ?? Auth::user();

        return DB::transaction(function () use ($refund, $cashbox, $paymentMethod, $defaultOccurredAt, $actor) {
            // Lock the refund row first to serialise concurrent pay attempts.
            $locked = Refund::query()->lockForUpdate()->findOrFail($refund->id);

            if (! $locked->canBePaid()) {
                throw new RuntimeException(
                    "Refund #{$locked->id} cannot be paid (status: {$locked->status})."
                );
            }

            // Lock the cashbox row so the balance check below sees a
            // stable, exclusive view of the ledger sum.
            $lockedCashbox = Cashbox::query()->lockForUpdate()->findOrFail($cashbox->id);

            // Re-run cheap checks on the locked rows (race protection
            // against deactivation that landed between the pre-tx check
            // and now).
            if (! $lockedCashbox->is_active) {
                throw new RuntimeException("Cashbox \"{$lockedCashbox->name}\" is inactive.");
            }

            // Balance guard (skipped if the cashbox permits negatives).
            $amount = (float) $locked->amount;
            if (! $lockedCashbox->allow_negative_balance) {
                $currentBalance = $lockedCashbox->balance();
                if ($currentBalance < $amount) {
                    throw new RuntimeException(
                        "Cashbox \"{$lockedCashbox->name}\" has insufficient balance "
                        . "({$currentBalance} < {$amount}) and does not permit negative balances."
                    );
                }
            }

            // Re-run the over-refund guard. Paying THIS refund doesn't
            // change the sum of active refunds (it stays active in the
            // 'paid' status), so the guard validates the current world
            // state and excludes this refund from the existing-sum to
            // avoid double-counting.
            $this->assertRefundableAmount(
                excludeRefundId: $locked->id,
                collectionId: $locked->collection_id,
                proposedAmount: $amount,
            );

            $occurredAt = $defaultOccurredAt ?? now();

            $tx = CashboxTransaction::create([
                'cashbox_id' => $lockedCashbox->id,
                'direction' => CashboxTransaction::DIRECTION_OUT,
                'amount' => -1 * round($amount, 2), // signed negative
                'occurred_at' => $occurredAt,
                'source_type' => CashboxTransaction::SOURCE_REFUND,
                'source_id' => $locked->id,
                'payment_method_id' => $paymentMethod->id,
                'notes' => "Refund #{$locked->id}"
                    . ($locked->order_id ? " for order #{$locked->order_id}" : '')
                    . ($locked->reason ? " — {$locked->reason}" : ''),
                'created_by' => $actor?->id,
            ]);

            AuditLogService::logModelChange($tx, 'cashbox_transaction.created', self::MODULE);

            $locked->fill([
                'status' => Refund::STATUS_PAID,
                'paid_by' => $actor?->id,
                'paid_at' => now(),
                'cashbox_id' => $lockedCashbox->id,
                'payment_method_id' => $paymentMethod->id,
                'cashbox_transaction_id' => $tx->id,
            ])->save();

            AuditLogService::log(
                action: 'refund_paid',
                module: self::MODULE,
                recordType: Refund::class,
                recordId: $locked->id,
                oldValues: ['status' => Refund::STATUS_APPROVED],
                newValues: [
                    'status' => Refund::STATUS_PAID,
                    'paid_by' => $actor?->id,
                    'paid_at' => $locked->paid_at?->toDateTimeString(),
                    'cashbox_id' => $lockedCashbox->id,
                    'payment_method_id' => $paymentMethod->id,
                    'cashbox_transaction_id' => $tx->id,
                    'amount' => (string) $locked->amount,
                ],
            );

            return $locked->fresh(['cashbox', 'paymentMethod', 'cashboxTransaction', 'paidBy']);
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
