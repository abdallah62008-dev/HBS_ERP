<?php

namespace App\Services;

use App\Models\Cashbox;
use App\Models\CashboxTransaction;
use App\Models\Collection;
use App\Models\PaymentMethod;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Finance Phase 3 — Collections × Cashboxes.
 *
 * The single canonical path for "this collection's money is now in our
 * cashbox". One call writes:
 *   1. one cashbox_transactions row (IN, signed positive)
 *   2. the collection row, stamping cashbox_transaction_id + cashbox_posted_at
 *
 * Both inside `DB::transaction` so partial state can never appear.
 * Both gated by service-layer guards described in
 * docs/finance/PHASE_0_FINANCIAL_BUSINESS_RULES.md §3.
 *
 * Reversal is NOT supported by this service (Phase 5+ adds refunds).
 * Mistakes are corrected later by writing a refund or an opposite
 * adjustment.
 */
class CollectionCashboxService
{
    public const MODULE = 'finance.collection';

    /**
     * Post a collection's collected amount to the chosen cashbox.
     *
     * Concurrency:
     * The critical guards (double-post + amount + status) run INSIDE the
     * transaction on a row-locked Collection so two concurrent post
     * requests cannot both pass `preventDoublePosting`. The first
     * acquires `lockForUpdate`, writes the cashbox transaction, stamps
     * the collection, and commits; the second blocks until the first
     * commits, then re-reads the row, sees `cashbox_transaction_id != null`,
     * and refuses.
     *
     * Cheap cashbox/payment-method active checks stay outside the
     * transaction so the caller gets a clean error before locking.
     *
     * @param  array{cashbox_id:int|string, payment_method_id:int|string, amount?:numeric, occurred_at?:?string}  $data
     */
    public function postCollectionToCashbox(Collection $collection, array $data): Collection
    {
        $cashbox = Cashbox::findOrFail((int) $data['cashbox_id']);
        $paymentMethod = PaymentMethod::findOrFail((int) $data['payment_method_id']);

        // Cheap checks outside the transaction.
        $this->validateCashboxActive($cashbox);
        $this->validatePaymentMethodActive($paymentMethod);

        $defaultOccurredAt = ! empty($data['occurred_at'])
            ? Carbon::parse($data['occurred_at'])
            : null;

        return DB::transaction(function () use ($collection, $cashbox, $paymentMethod, $data, $defaultOccurredAt) {
            // Lock the collection row to serialise concurrent post attempts.
            $locked = Collection::query()
                ->lockForUpdate()
                ->findOrFail($collection->id);

            // Override `amount_collected` if the caller passed one
            // (settlement flow may post a different amount than what's
            // currently stored). Done on the locked row.
            if (array_key_exists('amount', $data) && $data['amount'] !== null && $data['amount'] !== '') {
                $locked->amount_collected = round((float) $data['amount'], 2);
            }

            $occurredAt = $defaultOccurredAt
                ?? ($locked->settlement_date ? $locked->settlement_date->copy() : now());

            // Critical guards re-run on the locked row.
            $this->preventDoublePosting($locked);
            $this->validateCollectionCanBePosted($locked);
            // Phase 5F — block posting whose occurred_at is in a closed period.
            app(FinancePeriodService::class)->assertDateIsOpen($occurredAt);

            $tx = $this->createCashboxTransaction($locked, $cashbox, $paymentMethod, $occurredAt);
            return $this->updateCollectionCashboxFields($locked, $cashbox, $paymentMethod, $tx);
        });
    }

    /* ────────────────────── Guards ────────────────────── */

    public function preventDoublePosting(Collection $collection): void
    {
        if ($collection->cashbox_transaction_id !== null) {
            throw new RuntimeException(
                "Collection #{$collection->id} is already posted to a cashbox (transaction #{$collection->cashbox_transaction_id})."
            );
        }
    }

    public function validateCollectionCanBePosted(Collection $collection): void
    {
        $amount = (float) $collection->amount_collected;
        if ($amount <= 0) {
            throw new InvalidArgumentException(
                "Collection #{$collection->id} has no collected amount to post."
            );
        }

        if (! in_array($collection->collection_status, Collection::POSTABLE_STATUSES, true)) {
            throw new RuntimeException(
                "Collection status '{$collection->collection_status}' is not eligible for posting. "
                . 'Allowed: ' . implode(', ', Collection::POSTABLE_STATUSES) . '.'
            );
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

    /* ────────────────────── Writes ────────────────────── */

    private function createCashboxTransaction(
        Collection $collection,
        Cashbox $cashbox,
        PaymentMethod $paymentMethod,
        Carbon $occurredAt,
    ): CashboxTransaction {
        $amount = round((float) $collection->amount_collected, 2);
        $orderNumber = $collection->order?->order_number;
        $notes = $orderNumber
            ? "Collection for order {$orderNumber}"
            : "Collection #{$collection->id}";

        $tx = CashboxTransaction::create([
            'cashbox_id' => $cashbox->id,
            'direction' => CashboxTransaction::DIRECTION_IN,
            'amount' => $amount, // signed positive
            'occurred_at' => $occurredAt,
            'source_type' => CashboxTransaction::SOURCE_COLLECTION,
            'source_id' => $collection->id,
            'payment_method_id' => $paymentMethod->id,
            'notes' => $notes,
            'created_by' => Auth::id(),
        ]);

        AuditLogService::logModelChange($tx, 'cashbox_transaction.created', self::MODULE);

        return $tx;
    }

    private function updateCollectionCashboxFields(
        Collection $collection,
        Cashbox $cashbox,
        PaymentMethod $paymentMethod,
        CashboxTransaction $tx,
    ): Collection {
        $collection->fill([
            'cashbox_id' => $cashbox->id,
            'payment_method_id' => $paymentMethod->id,
            'cashbox_transaction_id' => $tx->id,
            'cashbox_posted_at' => now(),
            'updated_by' => Auth::id(),
        ])->save();

        AuditLogService::log(
            action: 'posted_to_cashbox',
            module: self::MODULE,
            recordType: Collection::class,
            recordId: $collection->id,
            oldValues: null,
            newValues: [
                'cashbox_id' => $cashbox->id,
                'payment_method_id' => $paymentMethod->id,
                'cashbox_transaction_id' => $tx->id,
                'amount' => (string) $collection->amount_collected,
            ],
        );

        return $collection->fresh(['cashbox', 'paymentMethod', 'cashboxTransaction']);
    }
}
