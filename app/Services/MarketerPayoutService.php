<?php

namespace App\Services;

use App\Models\Cashbox;
use App\Models\CashboxTransaction;
use App\Models\Marketer;
use App\Models\MarketerPayout;
use App\Models\MarketerTransaction;
use App\Models\MarketerWallet;
use App\Models\PaymentMethod;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Finance Phase 5D — Marketer payout business logic.
 *
 * Mirrors the Phase 5A/5B refund split:
 *   - paperwork transitions (`requestPayout`, `approve`, `reject`) are
 *     paperwork-only and never touch the cashbox or the ledger
 *   - `pay()` is the only entry point that writes money: one
 *     `cashbox_transactions` row (source_type='marketer_payout',
 *     signed-negative OUT) plus one `marketer_transactions` mirror row
 *     (`transaction_type='Payout', status='Paid'`) so the existing
 *     wallet recompute keeps `total_paid` accurate.
 *
 * Race protection: `pay()` locks the payout row AND the cashbox row
 * inside a single `DB::transaction`, then re-runs every guard against
 * the locked snapshot. Same pattern Phase 4.5 introduced and Phase 5B
 * reuses for refund payments.
 */
class MarketerPayoutService
{
    public const MODULE = 'finance.marketer_payout';

    public function __construct(
        private readonly MarketerWalletService $wallet,
    ) {}

    /**
     * Create a `requested` payout for the marketer.
     *
     * The balance guard is informational at this stage — the wallet
     * balance is recomputed from the existing ledger; a payout request
     * that exceeds the current balance is still allowed (the user might
     * back-date adjustments before approval), but is flagged for the
     * approver via the wallet snapshot.
     *
     * @param  array{amount: numeric, notes?: ?string}  $data
     */
    public function requestPayout(Marketer $marketer, ?User $user, array $data): MarketerPayout
    {
        $amount = round((float) ($data['amount'] ?? 0), 2);
        if ($amount <= 0) {
            throw new InvalidArgumentException('Payout amount must be greater than zero.');
        }

        $actor = $user ?? Auth::user();

        return DB::transaction(function () use ($marketer, $actor, $amount, $data) {
            $this->wallet->ensureWallet($marketer);

            $payout = MarketerPayout::create([
                'marketer_id' => $marketer->id,
                'amount' => $amount,
                'status' => MarketerPayout::STATUS_REQUESTED,
                'notes' => $data['notes'] ?? null,
                'requested_by' => $actor?->id,
            ]);

            AuditLogService::log(
                action: 'marketer_payout_created',
                module: self::MODULE,
                recordType: MarketerPayout::class,
                recordId: $payout->id,
                newValues: [
                    'marketer_id' => $marketer->id,
                    'amount' => (string) $amount,
                    'status' => MarketerPayout::STATUS_REQUESTED,
                    'requested_by' => $actor?->id,
                ],
            );

            return $payout;
        });
    }

    /**
     * Approve a `requested` payout. No cashbox effect.
     */
    public function approve(MarketerPayout $payout, ?User $user = null): MarketerPayout
    {
        if (! $payout->canBeApproved()) {
            throw new RuntimeException(
                "Marketer payout #{$payout->id} cannot be approved (status: {$payout->status})."
            );
        }

        $actor = $user ?? Auth::user();

        return DB::transaction(function () use ($payout, $actor) {
            $locked = MarketerPayout::query()->lockForUpdate()->findOrFail($payout->id);

            if (! $locked->canBeApproved()) {
                throw new RuntimeException(
                    "Marketer payout #{$locked->id} cannot be approved (status: {$locked->status})."
                );
            }

            $locked->fill([
                'status' => MarketerPayout::STATUS_APPROVED,
                'approved_by' => $actor?->id,
                'approved_at' => now(),
            ])->save();

            AuditLogService::log(
                action: 'marketer_payout_approved',
                module: self::MODULE,
                recordType: MarketerPayout::class,
                recordId: $locked->id,
                oldValues: ['status' => MarketerPayout::STATUS_REQUESTED],
                newValues: [
                    'status' => MarketerPayout::STATUS_APPROVED,
                    'approved_by' => $actor?->id,
                    'approved_at' => $locked->approved_at?->toDateTimeString(),
                ],
            );

            return $locked;
        });
    }

    /**
     * Reject a `requested` payout. No cashbox effect, terminal status.
     */
    public function reject(MarketerPayout $payout, ?User $user = null): MarketerPayout
    {
        if (! $payout->canBeRejected()) {
            throw new RuntimeException(
                "Marketer payout #{$payout->id} cannot be rejected (status: {$payout->status})."
            );
        }

        $actor = $user ?? Auth::user();

        return DB::transaction(function () use ($payout, $actor) {
            $locked = MarketerPayout::query()->lockForUpdate()->findOrFail($payout->id);

            if (! $locked->canBeRejected()) {
                throw new RuntimeException(
                    "Marketer payout #{$locked->id} cannot be rejected (status: {$locked->status})."
                );
            }

            $locked->fill([
                'status' => MarketerPayout::STATUS_REJECTED,
                'rejected_by' => $actor?->id,
                'rejected_at' => now(),
            ])->save();

            AuditLogService::log(
                action: 'marketer_payout_rejected',
                module: self::MODULE,
                recordType: MarketerPayout::class,
                recordId: $locked->id,
                oldValues: ['status' => MarketerPayout::STATUS_REQUESTED],
                newValues: [
                    'status' => MarketerPayout::STATUS_REJECTED,
                    'rejected_by' => $actor?->id,
                    'rejected_at' => $locked->rejected_at?->toDateTimeString(),
                ],
            );

            return $locked;
        });
    }

    /**
     * Pay an `approved` payout from a cashbox.
     *
     * Atomic: locks payout + cashbox, re-runs every guard, writes one
     * cashbox OUT transaction and one MarketerTransaction Payout-mirror
     * row, then stamps the payout with cashbox/payment/transaction IDs
     * and the `paid_*` audit fields.
     *
     * Guards (in order):
     *   1. payout.canBePaid()  — only `approved` rows with no existing
     *      cashbox_transaction_id / paid_at can be paid
     *   2. cashbox.is_active
     *   3. payment_method.is_active
     *   4. cashbox balance check when `allow_negative_balance` is false
     *
     * After the cashbox row is written, the wallet snapshot is rebuilt
     * from the now-updated marketer_transactions table.
     *
     * @param  array{cashbox_id:int|string, payment_method_id:int|string, occurred_at?:?string}  $data
     */
    public function pay(MarketerPayout $payout, ?User $user, array $data): MarketerPayout
    {
        if (! $payout->canBePaid()) {
            throw new RuntimeException(
                "Marketer payout #{$payout->id} cannot be paid (status: {$payout->status})."
            );
        }

        $cashbox = Cashbox::findOrFail((int) $data['cashbox_id']);
        $paymentMethod = PaymentMethod::findOrFail((int) $data['payment_method_id']);

        // Cheap, non-racy pre-checks outside the transaction.
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

        return DB::transaction(function () use ($payout, $cashbox, $paymentMethod, $defaultOccurredAt, $actor) {
            // 1. Lock the payout row — blocks concurrent pay attempts.
            $locked = MarketerPayout::query()->lockForUpdate()->findOrFail($payout->id);

            if (! $locked->canBePaid()) {
                throw new RuntimeException(
                    "Marketer payout #{$locked->id} cannot be paid (status: {$locked->status})."
                );
            }

            // 2. Lock the cashbox row so the balance read below sees a
            //    stable, exclusive view of the ledger sum.
            $lockedCashbox = Cashbox::query()->lockForUpdate()->findOrFail($cashbox->id);

            if (! $lockedCashbox->is_active) {
                throw new RuntimeException("Cashbox \"{$lockedCashbox->name}\" is inactive.");
            }

            // 3. Balance guard (skipped when the cashbox permits negatives).
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

            $occurredAt = $defaultOccurredAt ?? now();

            // Phase 5F — block payout payment if occurred_at is in a closed period.
            app(FinancePeriodService::class)->assertDateIsOpen($occurredAt);

            // 4. Write the cashbox OUT row (the canonical money movement).
            $tx = CashboxTransaction::create([
                'cashbox_id' => $lockedCashbox->id,
                'direction' => CashboxTransaction::DIRECTION_OUT,
                'amount' => -1 * round($amount, 2),
                'occurred_at' => $occurredAt,
                'source_type' => CashboxTransaction::SOURCE_MARKETER_PAYOUT,
                'source_id' => $locked->id,
                'payment_method_id' => $paymentMethod->id,
                'notes' => "Marketer payout #{$locked->id} for marketer #{$locked->marketer_id}"
                    . ($locked->notes ? " — {$locked->notes}" : ''),
                'created_by' => $actor?->id,
            ]);

            AuditLogService::logModelChange($tx, 'cashbox_transaction.created', self::MODULE);

            // 5. Mirror to marketer_transactions so the wallet recompute
            //    (which already understands `transaction_type='Payout',
            //    status='Paid'`) picks this up. The mirror row's
            //    source_type/source_id point back to the payout.
            $mirror = MarketerTransaction::create([
                'marketer_id' => $locked->marketer_id,
                'order_id' => null,
                'transaction_type' => MarketerTransaction::TYPE_PAYOUT,
                'net_profit' => round($amount, 2),
                'status' => 'Paid',
                'notes' => "Payout #{$locked->id} (cashbox #{$lockedCashbox->id}, tx #{$tx->id})",
                'source_type' => CashboxTransaction::SOURCE_MARKETER_PAYOUT,
                'source_id' => $locked->id,
                'created_by' => $actor?->id,
            ]);

            // 6. Stamp the payout with all linkage + audit fields.
            $locked->fill([
                'status' => MarketerPayout::STATUS_PAID,
                'paid_by' => $actor?->id,
                'paid_at' => now(),
                'cashbox_id' => $lockedCashbox->id,
                'payment_method_id' => $paymentMethod->id,
                'cashbox_transaction_id' => $tx->id,
                'marketer_transaction_id' => $mirror->id,
            ])->save();

            AuditLogService::log(
                action: 'marketer_payout_paid',
                module: self::MODULE,
                recordType: MarketerPayout::class,
                recordId: $locked->id,
                oldValues: ['status' => MarketerPayout::STATUS_APPROVED],
                newValues: [
                    'status' => MarketerPayout::STATUS_PAID,
                    'paid_by' => $actor?->id,
                    'paid_at' => $locked->paid_at?->toDateTimeString(),
                    'cashbox_id' => $lockedCashbox->id,
                    'payment_method_id' => $paymentMethod->id,
                    'cashbox_transaction_id' => $tx->id,
                    'marketer_transaction_id' => $mirror->id,
                    'amount' => (string) $locked->amount,
                ],
            );

            // 7. Recompute the wallet snapshot. This re-sums the
            //    marketer_transactions and bumps total_paid / balance.
            $marketer = Marketer::find($locked->marketer_id);
            if ($marketer) {
                $this->wallet->recalculateWallet($marketer);
            }

            return $locked->fresh(['cashbox', 'paymentMethod', 'cashboxTransaction', 'marketerTransaction', 'paidBy']);
        });
    }
}
