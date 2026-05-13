<?php

namespace App\Services;

use App\Models\Cashbox;
use App\Models\CashboxTransaction;
use App\Models\CashboxTransfer;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Finance Phase 2 — Cashbox Transfer business logic.
 *
 * Creating a transfer is a single atomic act: one `cashbox_transfers`
 * row plus exactly two `cashbox_transactions` (one negative on the
 * source, one positive on the destination). All three writes happen
 * inside `DB::transaction` so partial state is impossible.
 *
 * Reversals are NOT performed by editing or deleting the rows — they
 * are performed by creating a second, opposite transfer.
 */
class CashboxTransferService
{
    public const MODULE = 'finance.cashbox_transfer';

    public function __construct(private CashboxService $cashboxService) {}

    /**
     * Create a transfer + its two cashbox_transactions.
     *
     * Concurrency:
     * Both cashboxes are row-locked inside the transaction, in a
     * deterministic order (ascending id) to avoid deadlocks when two
     * users transfer between the same pair simultaneously. The balance
     * check is re-run AFTER the lock so two concurrent transfers from
     * the same source can't both pass `assertSufficientBalanceIfNeeded`
     * and overdraft a box flagged `allow_negative_balance = false`.
     *
     * Static (config-level) checks — different cashboxes, same currency,
     * positive amount, active cashboxes — stay outside the transaction
     * so the caller gets a clean error before any lock is acquired.
     *
     * @param  array{from_cashbox_id:int|string, to_cashbox_id:int|string, amount:numeric, occurred_at?:?string, reason?:?string}  $data
     */
    public function createTransfer(array $data): CashboxTransfer
    {
        $from = Cashbox::findOrFail((int) $data['from_cashbox_id']);
        $to = Cashbox::findOrFail((int) $data['to_cashbox_id']);
        $amount = (float) $data['amount'];
        $occurredAt = ! empty($data['occurred_at'])
            ? Carbon::parse($data['occurred_at'])
            : now();
        $reason = isset($data['reason']) ? (string) $data['reason'] : null;

        // Static checks outside the transaction.
        $this->assertDifferentCashboxes($from, $to);
        $this->assertCashboxesActive($from, $to);
        $this->assertSameCurrency($from, $to);
        $this->assertPositiveAmount($amount);

        return DB::transaction(function () use ($from, $to, $amount, $occurredAt, $reason) {
            // Lock both cashbox rows in id-ascending order so concurrent
            // transfers between the same pair (A→B and B→A) acquire locks
            // in the same order and cannot deadlock.
            $locked = Cashbox::query()
                ->whereIn('id', [$from->id, $to->id])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $lockedFrom = $locked->get($from->id) ?? Cashbox::findOrFail($from->id);
            $lockedTo = $locked->get($to->id) ?? Cashbox::findOrFail($to->id);

            // Re-check sufficient balance against the locked source row.
            $this->assertSufficientBalanceIfNeeded($lockedFrom, $amount);

            $transfer = CashboxTransfer::create([
                'from_cashbox_id' => $lockedFrom->id,
                'to_cashbox_id' => $lockedTo->id,
                'amount' => round($amount, 2),
                'occurred_at' => $occurredAt,
                'reason' => $reason,
                'created_by' => Auth::id(),
            ]);

            $this->createOutTransaction($transfer, $lockedFrom, $amount, $occurredAt);
            $this->createInTransaction($transfer, $lockedTo, $amount, $occurredAt);

            AuditLogService::logModelChange($transfer, 'created', self::MODULE);

            return $transfer->fresh(['fromCashbox', 'toCashbox', 'createdBy', 'transactions']);
        });
    }

    /* ────────────────────── Validation guards ────────────────────── */

    public function assertDifferentCashboxes(Cashbox $from, Cashbox $to): void
    {
        if ($from->id === $to->id) {
            throw new InvalidArgumentException('Source and destination cashboxes must be different.');
        }
    }

    public function assertCashboxesActive(Cashbox $from, Cashbox $to): void
    {
        if (! $from->is_active) {
            throw new RuntimeException("Source cashbox \"{$from->name}\" is inactive and cannot send transfers.");
        }
        if (! $to->is_active) {
            throw new RuntimeException("Destination cashbox \"{$to->name}\" is inactive and cannot receive transfers.");
        }
    }

    /**
     * Single-currency invariant. Phase 0 documented this as a service
     * guard — Phase 2 implements it. Cross-currency transfers are out
     * of scope; rejecting them surfaces future design gaps loudly.
     */
    public function assertSameCurrency(Cashbox $from, Cashbox $to): void
    {
        if ($from->currency_code !== $to->currency_code) {
            throw new InvalidArgumentException(
                "Cross-currency transfers are not supported ({$from->currency_code} → {$to->currency_code})."
            );
        }
    }

    public function assertPositiveAmount(float $amount): void
    {
        if ($amount <= 0) {
            throw new InvalidArgumentException('Transfer amount must be greater than zero.');
        }
    }

    /**
     * If the source cashbox forbids negative balances, the transfer
     * cannot push it below zero. Otherwise the transfer is permitted
     * even if the post-transfer balance is negative (matching the
     * documented per-cashbox `allow_negative_balance` flag).
     */
    public function assertSufficientBalanceIfNeeded(Cashbox $from, float $amount): void
    {
        if ($from->allow_negative_balance) {
            return;
        }
        $currentBalance = $from->balance();
        if ($currentBalance < $amount) {
            throw new RuntimeException(
                "Insufficient balance in \"{$from->name}\": {$currentBalance} < {$amount}."
            );
        }
    }

    /* ────────────────────── Ledger writes ────────────────────── */

    private function createOutTransaction(CashboxTransfer $transfer, Cashbox $from, float $amount, Carbon $occurredAt): CashboxTransaction
    {
        $tx = CashboxTransaction::create([
            'cashbox_id' => $from->id,
            'direction' => CashboxTransaction::DIRECTION_OUT,
            'amount' => -1 * round(abs($amount), 2),
            'occurred_at' => $occurredAt,
            'source_type' => CashboxTransaction::SOURCE_TRANSFER,
            'source_id' => $transfer->id,
            'transfer_id' => $transfer->id,
            'notes' => "Transfer to \"{$transfer->toCashbox->name}\"" . ($transfer->reason ? ": {$transfer->reason}" : ''),
            'created_by' => Auth::id(),
        ]);

        AuditLogService::logModelChange($tx, 'cashbox_transaction.created', self::MODULE);

        return $tx;
    }

    private function createInTransaction(CashboxTransfer $transfer, Cashbox $to, float $amount, Carbon $occurredAt): CashboxTransaction
    {
        $tx = CashboxTransaction::create([
            'cashbox_id' => $to->id,
            'direction' => CashboxTransaction::DIRECTION_IN,
            'amount' => round(abs($amount), 2),
            'occurred_at' => $occurredAt,
            'source_type' => CashboxTransaction::SOURCE_TRANSFER,
            'source_id' => $transfer->id,
            'transfer_id' => $transfer->id,
            'notes' => "Transfer from \"{$transfer->fromCashbox->name}\"" . ($transfer->reason ? ": {$transfer->reason}" : ''),
            'created_by' => Auth::id(),
        ]);

        AuditLogService::logModelChange($tx, 'cashbox_transaction.created', self::MODULE);

        return $tx;
    }
}
