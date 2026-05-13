<?php

namespace App\Services;

use App\Models\MarketerTransaction;
use App\Models\Order;
use App\Models\Refund;
use Illuminate\Support\Facades\Auth;

/**
 * Finance Phase 5D — Conservative profit reversal triggered by a paid
 * refund.
 *
 * Why this service exists
 * -----------------------
 * Two existing systems already handle most reversal cases:
 *
 *   1. Order status flip → MarketerWalletService::syncFromOrder()
 *      zeros the per-order profit row when the order becomes
 *      `Returned` or `Cancelled`.
 *   2. Adjustment-by-hand → Marketer wallet page lets staff post a
 *      `MarketerTransaction(type=Adjustment)` with a note.
 *
 * What is NOT handled
 * -------------------
 * A *partial* refund whose order stays `Delivered`. The order's
 * marketer_profit snapshot was set at delivery time; the refund
 * effectively reduces the realised revenue for that order, so the
 * marketer's earned profit should drop proportionally.
 *
 * What this service does
 * ----------------------
 * On a paid refund, when *all* of the following are true:
 *
 *   - the order has a marketer_id
 *   - the order has a positive `marketer_profit` snapshot
 *   - the order's `total_amount` (denominator for the ratio) is positive
 *   - the order's current status is NOT `Returned` / `Cancelled`
 *     (otherwise `syncFromOrder` has already / will zero the profit
 *     row — double-reversing would drop the wallet by 2× the right
 *     amount)
 *   - the refund is NOT linked to an OrderReturn (Phase 5C) — return-
 *     linked refunds are paired with a return that will eventually
 *     flip the order's status and trigger `syncFromOrder`, so any
 *     adjustment we write here would be double-counted
 *   - no Adjustment row already exists with
 *     (source_type='refund', source_id=$refund->id)
 *   - the refund amount itself is positive
 *
 * it writes a single `MarketerTransaction(type=Adjustment,
 * status=Approved, net_profit=-Δ, source_type='refund',
 * source_id=$refund->id)` where:
 *
 *   Δ = round( marketer_profit * (refund.amount / order.total_amount), 2 )
 *
 * clamped to `[0, marketer_profit_remaining_after_prior_reversals]`.
 * The wallet snapshot is recomputed so `total_earned` and `balance`
 * drop by Δ.
 *
 * The service NEVER throws — failing-silent is by design. Refund
 * payment is the canonical action; reversal is best-effort book-
 * keeping. If conditions don't apply, `reverseFromPaidRefund` returns
 * `null` and leaves the wallet alone.
 */
class MarketerProfitReversalService
{
    public const MODULE = 'finance.marketer_profit_reversal';
    public const SOURCE_REFUND = 'refund';

    public function __construct(
        private readonly MarketerWalletService $wallet,
    ) {}

    /**
     * Apply (or skip) the proportional reversal for a paid refund.
     *
     * Idempotent: a second call for the same refund is a no-op.
     */
    public function reverseFromPaidRefund(Refund $refund): ?MarketerTransaction
    {
        if ($refund->status !== Refund::STATUS_PAID) {
            return null;
        }

        $amount = (float) $refund->amount;
        if ($amount <= 0) {
            return null;
        }

        // The refund must be tied to an order for us to derive the
        // marketer + profit snapshot. Order-only refunds, collection-
        // only refunds, and return-only refunds all carry order_id
        // (see Phase 5A/5C wiring), so this is rarely null in practice.
        if (! $refund->order_id) {
            return null;
        }

        // Return-linked refunds (Phase 5C) ride the return's order-status
        // flip → `MarketerWalletService::syncFromOrder` will zero the
        // whole per-order profit row. Writing an adjustment here would
        // be double-counted. Defer to the return path entirely.
        if (! empty($refund->order_return_id)) {
            return null;
        }

        /** @var Order|null $order */
        $order = Order::find($refund->order_id);
        if (! $order || ! $order->marketer_id) {
            return null;
        }

        // Skip if the order is already in a status that triggers the
        // legacy full-row reversal — `syncFromOrder` has either already
        // zeroed the per-order Earned row (Scenario B) or is about to
        // (and we'd double-reverse if we ran).
        if (in_array($order->status, ['Returned', 'Cancelled'], true)) {
            return null;
        }

        $orderProfit = (float) ($order->marketer_profit ?? 0);
        if ($orderProfit <= 0) {
            // No marketer profit captured — nothing to reverse.
            return null;
        }

        $orderTotal = (float) ($order->total_amount ?? 0);
        if ($orderTotal <= 0) {
            // Cannot compute a ratio. Skip silently.
            return null;
        }

        // Idempotency: skip if we already posted a reversal for this refund.
        $existing = MarketerTransaction::query()
            ->where('source_type', self::SOURCE_REFUND)
            ->where('source_id', $refund->id)
            ->first();

        if ($existing) {
            return $existing;
        }

        // Total reversal already booked against this order (in case
        // multiple refunds have been paid). Reversal rows always have
        // negative `net_profit`, so summing them gives a negative total;
        // take the magnitude to get the "already reversed" amount.
        $reversedSum = (float) MarketerTransaction::query()
            ->where('source_type', self::SOURCE_REFUND)
            ->where('order_id', $order->id)
            ->sum('net_profit');
        $alreadyReversed = abs($reversedSum);

        $remaining = max(0.0, round($orderProfit - $alreadyReversed, 2));
        if ($remaining <= 0) {
            return null;
        }

        $ratio = min(1.0, $amount / $orderTotal);
        $delta = round(min($remaining, $orderProfit * $ratio), 2);
        if ($delta <= 0) {
            return null;
        }

        $tx = MarketerTransaction::create([
            'marketer_id' => $order->marketer_id,
            'order_id' => $order->id,
            'transaction_type' => MarketerTransaction::TYPE_ADJUSTMENT,
            'net_profit' => -1 * $delta,
            'status' => 'Approved',
            'notes' => "Profit reversal from paid refund #{$refund->id}"
                . " (ratio " . number_format($ratio, 4) . " of order #{$order->id})",
            'source_type' => self::SOURCE_REFUND,
            'source_id' => $refund->id,
            'created_by' => Auth::id(),
        ]);

        // Refresh the wallet snapshot so balance drops by Δ.
        $marketer = $order->marketer;
        if ($marketer) {
            $this->wallet->recalculateWallet($marketer);
        }

        AuditLogService::log(
            action: 'marketer_profit_reversed',
            module: self::MODULE,
            recordType: MarketerTransaction::class,
            recordId: $tx->id,
            newValues: [
                'marketer_id' => $order->marketer_id,
                'order_id' => $order->id,
                'refund_id' => $refund->id,
                'delta' => -$delta,
                'ratio' => $ratio,
                'orig_marketer_profit' => $orderProfit,
                'order_total_amount' => $orderTotal,
            ],
        );

        return $tx;
    }
}
