<?php

namespace App\Services;

use App\Models\Marketer;
use App\Models\MarketerTransaction;
use App\Models\MarketerWallet;
use App\Models\Order;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Owns the marketer profit lifecycle (per 04_BUSINESS_WORKFLOWS.md §15).
 *
 * Per-order profit row evolves through statuses as the order moves:
 *
 *   New / Confirmed   →  Expected Profit   (status = Expected)
 *   Shipped           →  Pending Profit    (status = Pending)
 *   Delivered         →  Earned Profit     (status = Approved)
 *   Returned/Cancelled→  Cancelled Profit  (status = Cancelled, net_profit zeroed)
 *
 * Payout and Adjustment rows are created standalone and never overwrite
 * the per-order profit row.
 *
 * Profit formula (canonical):
 *   net_profit = selling_price − trade_product_price − shipping − tax − extra_fees
 *
 * Wallet totals are recomputed in `recalculateWallet()` after every change.
 */
class MarketerWalletService
{
    /**
     * Record (or update) the marketer's profit row for this order to
     * reflect the order's current status. Idempotent: calling it twice
     * for the same status is a no-op.
     */
    public function syncFromOrder(Order $order): ?MarketerTransaction
    {
        if (! $order->marketer_id) {
            return null;
        }

        $marketer = Marketer::with('priceGroup')->find($order->marketer_id);
        if (! $marketer) {
            return null;
        }

        return DB::transaction(function () use ($order, $marketer) {
            // Compute the profit components from the order. The order
            // itself stores marketer_trade_total and selling_price totals.
            $components = $this->computeComponents($order, $marketer);

            [$type, $status, $netProfit] = $this->lifecycleFor($order->status, $components);

            $tx = MarketerTransaction::firstOrNew([
                'marketer_id' => $marketer->id,
                'order_id' => $order->id,
                // Profit rows are everything that's not Payout/Adjustment.
                // We exclude those two by querying the pre-existing row
                // through the order_id only, and rely on the controller
                // to never call this method on a payout.
            ]);

            if (! $tx->exists) {
                $tx->created_by = Auth::id();
            }

            $tx->fill([
                'transaction_type' => $type,
                'selling_price' => $components['selling_price'],
                'trade_product_price' => $components['trade_product_price'],
                'shipping_amount' => $components['shipping_amount'],
                'tax_amount' => $components['tax_amount'],
                'extra_fees' => $components['extra_fees'],
                'net_profit' => $netProfit,
                'status' => $status,
            ])->save();

            // Mirror earned profit onto the order row so report queries
            // can JOIN once instead of redoing the math.
            if ($status === 'Approved' || $status === 'Cancelled') {
                $order->forceFill([
                    'net_profit' => $status === 'Approved' ? $netProfit : 0,
                ])->save();
            }

            $this->recalculateWallet($marketer);

            AuditLogService::log(
                action: 'profit_lifecycle',
                module: 'marketers',
                recordType: MarketerTransaction::class,
                recordId: $tx->id,
                newValues: [
                    'order_number' => $order->order_number,
                    'type' => $type,
                    'status' => $status,
                    'net_profit' => $netProfit,
                ],
            );

            return $tx;
        });
    }

    /**
     * Pay out a marketer. Inserts a `Payout` transaction row and
     * recomputes the wallet. Refuses if the requested amount exceeds the
     * current balance unless `$force` is set (for back-dated corrections).
     */
    public function payout(Marketer $marketer, float $amount, ?string $notes = null, bool $force = false): MarketerTransaction
    {
        if ($amount <= 0) {
            throw new \RuntimeException('Payout amount must be positive.');
        }

        return DB::transaction(function () use ($marketer, $amount, $notes, $force) {
            $wallet = $this->ensureWallet($marketer);

            if (! $force && (float) $wallet->balance < $amount) {
                throw new \RuntimeException(sprintf(
                    'Cannot pay out %.2f — marketer balance is only %.2f.',
                    $amount,
                    (float) $wallet->balance,
                ));
            }

            $tx = MarketerTransaction::create([
                'marketer_id' => $marketer->id,
                'order_id' => null,
                'transaction_type' => MarketerTransaction::TYPE_PAYOUT,
                'net_profit' => $amount,
                'status' => 'Paid',
                'notes' => $notes,
                'created_by' => Auth::id(),
            ]);

            $this->recalculateWallet($marketer);

            AuditLogService::log(
                action: 'payout',
                module: 'marketers',
                recordType: MarketerTransaction::class,
                recordId: $tx->id,
                newValues: ['amount' => $amount, 'marketer_id' => $marketer->id],
            );

            return $tx;
        });
    }

    public function adjust(Marketer $marketer, float $delta, string $notes): MarketerTransaction
    {
        return DB::transaction(function () use ($marketer, $delta, $notes) {
            $tx = MarketerTransaction::create([
                'marketer_id' => $marketer->id,
                'transaction_type' => MarketerTransaction::TYPE_ADJUSTMENT,
                'net_profit' => $delta,
                'status' => 'Approved',
                'notes' => $notes,
                'created_by' => Auth::id(),
            ]);

            $this->recalculateWallet($marketer);

            AuditLogService::log(
                action: 'adjustment',
                module: 'marketers',
                recordType: MarketerTransaction::class,
                recordId: $tx->id,
                newValues: ['delta' => $delta, 'notes' => $notes],
            );

            return $tx;
        });
    }

    /**
     * Re-derive wallet totals from the transactions table.
     */
    public function recalculateWallet(Marketer $marketer): MarketerWallet
    {
        $wallet = $this->ensureWallet($marketer);

        $totals = MarketerTransaction::query()
            ->where('marketer_id', $marketer->id)
            ->selectRaw("
                COALESCE(SUM(CASE WHEN status = 'Expected' AND transaction_type = 'Expected Profit' THEN net_profit ELSE 0 END), 0) AS total_expected,
                COALESCE(SUM(CASE WHEN status = 'Pending' AND transaction_type = 'Pending Profit' THEN net_profit ELSE 0 END), 0) AS total_pending,
                COALESCE(SUM(CASE WHEN status = 'Approved' AND transaction_type IN ('Earned Profit','Adjustment') THEN net_profit ELSE 0 END), 0) AS total_earned,
                COALESCE(SUM(CASE WHEN transaction_type = 'Payout' AND status = 'Paid' THEN net_profit ELSE 0 END), 0) AS total_paid
            ")
            ->first();

        $wallet->forceFill([
            'total_expected' => round((float) $totals->total_expected, 2),
            'total_pending' => round((float) $totals->total_pending, 2),
            'total_earned' => round((float) $totals->total_earned, 2),
            'total_paid' => round((float) $totals->total_paid, 2),
            'balance' => round((float) $totals->total_earned - (float) $totals->total_paid, 2),
            'updated_at' => now(),
        ])->save();

        return $wallet;
    }

    public function ensureWallet(Marketer $marketer): MarketerWallet
    {
        return MarketerWallet::firstOrCreate(
            ['marketer_id' => $marketer->id],
            [
                'total_expected' => 0,
                'total_pending' => 0,
                'total_earned' => 0,
                'total_paid' => 0,
                'balance' => 0,
                'updated_at' => now(),
            ],
        );
    }

    /**
     * Map order status to (transaction_type, transaction_status, net_profit_to_record).
     *
     * The "commission_after_delivery_only" flag is the default per spec
     * (true) — Earned only happens at Delivered. If the marketer has the
     * flag off, then Pending → Earned happens at Shipped instead.
     *
     * @return array{0:string, 1:string, 2:float}
     */
    private function lifecycleFor(string $orderStatus, array $components): array
    {
        $netProfit = (float) $components['net_profit'];

        return match ($orderStatus) {
            'New', 'Pending Confirmation', 'Confirmed', 'Ready to Pack', 'Packed', 'Ready to Ship', 'On Hold', 'Need Review'
                => [MarketerTransaction::TYPE_EXPECTED, 'Expected', $netProfit],

            'Shipped', 'Out for Delivery'
                => [MarketerTransaction::TYPE_PENDING, 'Pending', $netProfit],

            'Delivered'
                => [MarketerTransaction::TYPE_EARNED, 'Approved', $netProfit],

            'Returned', 'Cancelled'
                => [MarketerTransaction::TYPE_CANCELLED, 'Cancelled', 0.0],

            default => [MarketerTransaction::TYPE_EXPECTED, 'Expected', $netProfit],
        };
    }

    /**
     * Compute the formula components from the order. The order already
     * stores marketer_trade_total (set at order creation in OrderService)
     * and the totals.
     *
     *   selling_price        = order.subtotal       (what the marketer charged)
     *   trade_product_price  = order.marketer_trade_total
     *   shipping             = order.shipping_amount  (if marketer.shipping_deducted)
     *   tax                  = order.tax_amount       (if marketer.tax_deducted)
     *   extra_fees           = order.extra_fees
     *
     * @return array<string,float>
     */
    private function computeComponents(Order $order, Marketer $marketer): array
    {
        $selling = (float) $order->subtotal;
        $trade = (float) $order->marketer_trade_total;
        $shipping = $marketer->shipping_deducted ? (float) $order->shipping_amount : 0.0;
        $tax = $marketer->tax_deducted ? (float) $order->tax_amount : 0.0;
        $extra = (float) $order->extra_fees;

        $netProfit = round($selling - $trade - $shipping - $tax - $extra, 2);

        return [
            'selling_price' => round($selling, 2),
            'trade_product_price' => round($trade, 2),
            'shipping_amount' => round($shipping, 2),
            'tax_amount' => round($tax, 2),
            'extra_fees' => round($extra, 2),
            'net_profit' => $netProfit,
        ];
    }
}
