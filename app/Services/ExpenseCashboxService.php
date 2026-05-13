<?php

namespace App\Services;

use App\Models\Cashbox;
use App\Models\CashboxTransaction;
use App\Models\Expense;
use App\Models\PaymentMethod;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Finance Phase 4 — Expenses × Cashboxes.
 *
 * Mirrors CollectionCashboxService for symmetry. The single canonical
 * path for "this expense's money has left our cashbox":
 *
 *   1. one cashbox_transactions row (OUT, signed negative)
 *   2. the expense row, stamping cashbox_transaction_id +
 *      cashbox_posted_at + the snapshotted cashbox/payment ids
 *
 * Both inside DB::transaction so partial state cannot appear. All
 * guards run BEFORE the transaction so a clean exception bubbles to
 * the controller without leaving a half-written cashbox row behind.
 *
 * Reversal is NOT supported here. A wrongly-posted expense waits for
 * Phase 5+ (refunds / adjustments).
 */
class ExpenseCashboxService
{
    public const MODULE = 'finance.expense';

    /**
     * Post an expense's amount as a cashbox OUT transaction.
     *
     * @param  array{cashbox_id:int|string, payment_method_id:int|string, amount?:numeric, occurred_at?:?string}  $data
     */
    public function postExpenseToCashbox(Expense $expense, array $data): Expense
    {
        $cashbox = Cashbox::findOrFail((int) $data['cashbox_id']);
        $paymentMethod = PaymentMethod::findOrFail((int) $data['payment_method_id']);

        if (array_key_exists('amount', $data) && $data['amount'] !== null && $data['amount'] !== '') {
            $expense->amount = round((float) $data['amount'], 2);
        }

        $occurredAt = ! empty($data['occurred_at'])
            ? Carbon::parse($data['occurred_at'])
            : ($expense->expense_date ? $expense->expense_date->copy() : now());

        // Guards before the transaction.
        $this->preventDoublePosting($expense);
        $this->validateExpenseCanBePosted($expense);
        $this->validateCashboxActive($cashbox);
        $this->validatePaymentMethodActive($paymentMethod);
        $this->validateSameCurrency($expense, $cashbox);
        $this->validateSufficientBalanceIfNeeded($cashbox, (float) $expense->amount);

        return DB::transaction(function () use ($expense, $cashbox, $paymentMethod, $occurredAt) {
            $tx = $this->createCashboxTransaction($expense, $cashbox, $paymentMethod, $occurredAt);
            return $this->updateExpenseCashboxFields($expense, $cashbox, $paymentMethod, $tx);
        });
    }

    /* ────────────────────── Guards ────────────────────── */

    public function preventDoublePosting(Expense $expense): void
    {
        if ($expense->cashbox_transaction_id !== null) {
            throw new RuntimeException(
                "Expense #{$expense->id} is already posted to a cashbox (transaction #{$expense->cashbox_transaction_id})."
            );
        }
    }

    public function validateExpenseCanBePosted(Expense $expense): void
    {
        if ((float) $expense->amount <= 0) {
            throw new InvalidArgumentException("Expense #{$expense->id} has no amount to post.");
        }
    }

    public function validateCashboxActive(Cashbox $cashbox): void
    {
        if (! $cashbox->is_active) {
            throw new RuntimeException("Cashbox \"{$cashbox->name}\" is inactive.");
        }
    }

    public function validatePaymentMethodActive(PaymentMethod $paymentMethod): void
    {
        if (! $paymentMethod->is_active) {
            throw new RuntimeException("Payment method \"{$paymentMethod->name}\" is inactive.");
        }
    }

    /**
     * Single-currency invariant. Expense and target cashbox must agree.
     */
    public function validateSameCurrency(Expense $expense, Cashbox $cashbox): void
    {
        if ($expense->currency_code && $expense->currency_code !== $cashbox->currency_code) {
            throw new InvalidArgumentException(
                "Expense currency ({$expense->currency_code}) does not match cashbox \"{$cashbox->name}\" ({$cashbox->currency_code})."
            );
        }
    }

    /**
     * If the cashbox forbids negative balances, an OUT transaction
     * cannot push it below zero.
     */
    public function validateSufficientBalanceIfNeeded(Cashbox $cashbox, float $amount): void
    {
        if ($cashbox->allow_negative_balance) {
            return;
        }
        $balance = $cashbox->balance();
        if ($balance < $amount) {
            throw new RuntimeException(
                "Cashbox \"{$cashbox->name}\" has insufficient balance ({$balance} < {$amount}) and does not permit negative balances."
            );
        }
    }

    /* ────────────────────── Writes ────────────────────── */

    private function createCashboxTransaction(
        Expense $expense,
        Cashbox $cashbox,
        PaymentMethod $paymentMethod,
        Carbon $occurredAt,
    ): CashboxTransaction {
        $amount = round((float) $expense->amount, 2);

        $tx = CashboxTransaction::create([
            'cashbox_id' => $cashbox->id,
            'direction' => CashboxTransaction::DIRECTION_OUT,
            'amount' => -1 * $amount, // signed negative
            'occurred_at' => $occurredAt,
            'source_type' => CashboxTransaction::SOURCE_EXPENSE,
            'source_id' => $expense->id,
            'payment_method_id' => $paymentMethod->id,
            'notes' => "Expense: {$expense->title}",
            'created_by' => Auth::id(),
        ]);

        AuditLogService::logModelChange($tx, 'cashbox_transaction.created', self::MODULE);

        return $tx;
    }

    private function updateExpenseCashboxFields(
        Expense $expense,
        Cashbox $cashbox,
        PaymentMethod $paymentMethod,
        CashboxTransaction $tx,
    ): Expense {
        $expense->fill([
            'cashbox_id' => $cashbox->id,
            'payment_method_id' => $paymentMethod->id,
            'cashbox_transaction_id' => $tx->id,
            'cashbox_posted_at' => now(),
            'updated_by' => Auth::id(),
        ])->save();

        AuditLogService::log(
            action: 'posted_to_cashbox',
            module: self::MODULE,
            recordType: Expense::class,
            recordId: $expense->id,
            oldValues: null,
            newValues: [
                'cashbox_id' => $cashbox->id,
                'payment_method_id' => $paymentMethod->id,
                'cashbox_transaction_id' => $tx->id,
                'amount' => (string) $expense->amount,
            ],
        );

        return $expense->fresh(['cashbox', 'paymentMethod', 'cashboxTransaction']);
    }
}
