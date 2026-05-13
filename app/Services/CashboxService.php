<?php

namespace App\Services;

use App\Models\Cashbox;
use App\Models\CashboxTransaction;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Finance Phase 1 — Cashbox business logic.
 *
 * All mutations to cashboxes and cashbox_transactions go through this
 * service so the business rules in docs/finance/PHASE_0_FINANCIAL_BUSINESS_RULES.md
 * are enforced in exactly one place:
 *
 *   - Balance is always SUM(cashbox_transactions.amount); never stored.
 *   - Opening balance writes one cashbox_transaction (source_type=opening_balance).
 *   - Opening balance and currency_code are immutable after any transaction.
 *   - Cashboxes are never hard-deleted. Retirement is `is_active = false`.
 *   - Manual adjustments require notes.
 *   - Direction must agree with amount sign.
 *   - Inactive cashboxes refuse new transactions.
 *   - Every mutation writes an AuditLogService entry.
 */
class CashboxService
{
    public const MODULE = 'finance.cashbox';

    /* ────────────────────── Cashbox lifecycle ────────────────────── */

    /**
     * Create a cashbox, optionally with an opening balance.
     *
     * @param  array{name:string,type:string,currency_code:string,opening_balance?:numeric,allow_negative_balance?:bool,is_active?:bool,description?:?string}  $data
     */
    public function createCashbox(array $data): Cashbox
    {
        return DB::transaction(function () use ($data) {
            $opening = (float) ($data['opening_balance'] ?? 0);

            $cashbox = Cashbox::create([
                'name' => $data['name'],
                'type' => $data['type'],
                'currency_code' => $data['currency_code'],
                'opening_balance' => $opening,
                'allow_negative_balance' => (bool) ($data['allow_negative_balance'] ?? true),
                'is_active' => (bool) ($data['is_active'] ?? true),
                'description' => $data['description'] ?? null,
                'created_by' => Auth::id(),
                'updated_by' => Auth::id(),
            ]);

            AuditLogService::logModelChange($cashbox, 'created', self::MODULE);

            if (abs($opening) > 0) {
                $this->createOpeningBalanceTransaction($cashbox, $opening);
            }

            return $cashbox->refresh();
        });
    }

    /**
     * Update a cashbox. opening_balance and currency_code are NEVER
     * accepted here — they are stripped if present in the payload.
     */
    public function updateCashbox(Cashbox $cashbox, array $data): Cashbox
    {
        unset($data['opening_balance'], $data['currency_code'], $data['created_by']);

        $cashbox->fill([
            ...$data,
            'updated_by' => Auth::id(),
        ])->save();

        AuditLogService::logModelChange($cashbox, 'updated', self::MODULE);

        return $cashbox->refresh();
    }

    public function deactivateCashbox(Cashbox $cashbox): Cashbox
    {
        if (! $cashbox->is_active) {
            return $cashbox;
        }

        $cashbox->is_active = false;
        $cashbox->updated_by = Auth::id();
        $cashbox->save();

        AuditLogService::log(
            action: 'deactivated',
            module: self::MODULE,
            recordType: Cashbox::class,
            recordId: $cashbox->id,
            oldValues: ['is_active' => true],
            newValues: ['is_active' => false],
        );

        return $cashbox->refresh();
    }

    public function reactivateCashbox(Cashbox $cashbox): Cashbox
    {
        if ($cashbox->is_active) {
            return $cashbox;
        }

        $cashbox->is_active = true;
        $cashbox->updated_by = Auth::id();
        $cashbox->save();

        AuditLogService::log(
            action: 'reactivated',
            module: self::MODULE,
            recordType: Cashbox::class,
            recordId: $cashbox->id,
            oldValues: ['is_active' => false],
            newValues: ['is_active' => true],
        );

        return $cashbox->refresh();
    }

    /* ────────────────────── Transactions ────────────────────── */

    /**
     * Write the auto opening_balance row. Called by createCashbox(); not
     * usable for editing the opening balance after the fact.
     */
    public function createOpeningBalanceTransaction(Cashbox $cashbox, float $amount): CashboxTransaction
    {
        if ($cashbox->hasTransactions()) {
            throw new RuntimeException('Cashbox already has transactions — opening balance cannot be written again.');
        }

        $signed = $this->normalizeTransactionAmount(
            direction: $amount >= 0 ? CashboxTransaction::DIRECTION_IN : CashboxTransaction::DIRECTION_OUT,
            amount: $amount,
        );

        $tx = CashboxTransaction::create([
            'cashbox_id' => $cashbox->id,
            'direction' => $signed['direction'],
            'amount' => $signed['amount'],
            'occurred_at' => now(),
            'source_type' => CashboxTransaction::SOURCE_OPENING_BALANCE,
            'source_id' => null,
            'notes' => 'Opening balance set at cashbox creation.',
            'created_by' => Auth::id(),
        ]);

        AuditLogService::logModelChange($tx, 'cashbox_transaction.created', self::MODULE);

        return $tx;
    }

    /**
     * Create a manual adjustment row.
     *
     * @param  array{direction:string,amount:numeric,notes:string,occurred_at?:?string}  $data
     */
    public function createAdjustmentTransaction(Cashbox $cashbox, array $data): CashboxTransaction
    {
        $this->assertCanUseCashbox($cashbox);

        $notes = trim((string) ($data['notes'] ?? ''));
        if ($notes === '') {
            throw new InvalidArgumentException('Adjustments require notes.');
        }

        $signed = $this->normalizeTransactionAmount(
            direction: (string) $data['direction'],
            amount: (float) $data['amount'],
        );

        return DB::transaction(function () use ($cashbox, $signed, $notes, $data) {
            $tx = CashboxTransaction::create([
                'cashbox_id' => $cashbox->id,
                'direction' => $signed['direction'],
                'amount' => $signed['amount'],
                'occurred_at' => isset($data['occurred_at']) && $data['occurred_at']
                    ? \Carbon\Carbon::parse($data['occurred_at'])
                    : now(),
                'source_type' => CashboxTransaction::SOURCE_ADJUSTMENT,
                'source_id' => null,
                'notes' => $notes,
                'created_by' => Auth::id(),
            ]);

            AuditLogService::logModelChange($tx, 'cashbox_transaction.created', self::MODULE);

            return $tx;
        });
    }

    /* ────────────────────── Queries ────────────────────── */

    public function calculateBalance(Cashbox $cashbox): float
    {
        return $cashbox->balance();
    }

    /* ────────────────────── Guards ────────────────────── */

    /**
     * Throws when the cashbox is not usable for a new transaction.
     * (Inactive cashboxes refuse new financial operations.)
     */
    public function assertCanUseCashbox(Cashbox $cashbox): void
    {
        if (! $cashbox->is_active) {
            throw new RuntimeException("Cashbox {$cashbox->id} is inactive and cannot receive new transactions.");
        }
    }

    /**
     * Canonicalises (direction, amount) into a signed amount:
     *   - direction='in'  ⇒ amount must end positive
     *   - direction='out' ⇒ amount must end negative
     *
     * Accepts either a signed amount or a positive amount + direction;
     * always returns the signed form so cashbox_transactions.amount is
     * consistent for `SUM(amount)` balance computation.
     *
     * @return array{direction:string, amount:float}
     */
    public function normalizeTransactionAmount(string $direction, float $amount): array
    {
        if (! in_array($direction, [CashboxTransaction::DIRECTION_IN, CashboxTransaction::DIRECTION_OUT], true)) {
            throw new InvalidArgumentException("Invalid direction: {$direction}");
        }

        if ($amount === 0.0) {
            throw new InvalidArgumentException('Transaction amount cannot be zero.');
        }

        $abs = abs($amount);
        $signed = $direction === CashboxTransaction::DIRECTION_IN ? $abs : -$abs;

        return ['direction' => $direction, 'amount' => $signed];
    }
}
