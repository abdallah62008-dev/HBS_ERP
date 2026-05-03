<?php

namespace App\Services;

use App\Models\AdCampaign;
use App\Models\Collection;
use App\Models\Expense;
use App\Models\Marketer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderReturn;
use App\Models\Product;
use App\Models\Shipment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Single home for the heavy aggregate queries that power Phase 6 reports.
 *
 * Every method takes optional `$from` / `$to` date strings (Y-m-d).
 * When omitted, reports default to the current month.
 */
class ReportsService
{
    /**
     * @return array{from:string, to:string}
     */
    public function dateRange(?string $from, ?string $to): array
    {
        return [
            'from' => $from ?: now()->startOfMonth()->toDateString(),
            'to' => $to ?: now()->endOfMonth()->toDateString(),
        ];
    }

    /**
     * Sales over time: by-day series + totals.
     */
    public function sales(?string $from, ?string $to): array
    {
        ['from' => $from, 'to' => $to] = $this->dateRange($from, $to);

        $byDay = Order::query()
            ->whereBetween('created_at', [$from . ' 00:00:00', $to . ' 23:59:59'])
            ->selectRaw('DATE(created_at) AS day,
                COUNT(*) AS orders,
                COALESCE(SUM(total_amount), 0) AS revenue,
                SUM(CASE WHEN status = "Delivered" THEN 1 ELSE 0 END) AS delivered,
                SUM(CASE WHEN status = "Returned" THEN 1 ELSE 0 END) AS returned,
                COALESCE(SUM(CASE WHEN status = "Delivered" THEN total_amount ELSE 0 END), 0) AS delivered_revenue
            ')
            ->groupBy('day')
            ->orderBy('day')
            ->get();

        $totals = Order::query()
            ->whereBetween('created_at', [$from . ' 00:00:00', $to . ' 23:59:59'])
            ->selectRaw('
                COUNT(*) AS orders,
                COALESCE(SUM(total_amount), 0) AS revenue,
                SUM(CASE WHEN status = "Delivered" THEN 1 ELSE 0 END) AS delivered,
                SUM(CASE WHEN status = "Returned" THEN 1 ELSE 0 END) AS returned,
                SUM(CASE WHEN status = "Cancelled" THEN 1 ELSE 0 END) AS cancelled,
                COALESCE(SUM(CASE WHEN status = "Delivered" THEN total_amount ELSE 0 END), 0) AS delivered_revenue
            ')
            ->first();

        return ['from' => $from, 'to' => $to, 'by_day' => $byDay, 'totals' => $totals];
    }

    /**
     * Profit report: revenue, COGS, gross profit, expenses, net profit.
     */
    public function profit(?string $from, ?string $to): array
    {
        ['from' => $from, 'to' => $to] = $this->dateRange($from, $to);

        $deliveredOrders = Order::query()
            ->where('status', 'Delivered')
            ->whereBetween('delivered_at', [$from . ' 00:00:00', $to . ' 23:59:59'])
            ->selectRaw('
                COUNT(*) AS orders,
                COALESCE(SUM(total_amount), 0) AS revenue,
                COALESCE(SUM(product_cost_total), 0) AS cogs,
                COALESCE(SUM(shipping_amount), 0) AS shipping,
                COALESCE(SUM(gross_profit), 0) AS gross_profit,
                COALESCE(SUM(net_profit), 0) AS net_profit
            ')
            ->first();

        $expenses = (float) Expense::query()
            ->whereBetween('expense_date', [$from, $to])
            ->sum('amount');

        $finalNet = (float) ($deliveredOrders->net_profit ?? 0) - $expenses;

        return [
            'from' => $from, 'to' => $to,
            'delivered' => $deliveredOrders,
            'expenses' => $expenses,
            'final_net_profit' => round($finalNet, 2),
        ];
    }

    /**
     * Per-product profitability — units sold, revenue, profit, return rate.
     * Returns top 50 by revenue.
     */
    public function productProfitability(?string $from, ?string $to, int $limit = 50): array
    {
        ['from' => $from, 'to' => $to] = $this->dateRange($from, $to);

        $rows = OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereBetween('orders.created_at', [$from . ' 00:00:00', $to . ' 23:59:59'])
            ->selectRaw('
                order_items.product_id,
                MAX(order_items.product_name) AS product_name,
                MAX(order_items.sku) AS sku,
                COALESCE(SUM(order_items.quantity), 0) AS units_total,
                COALESCE(SUM(CASE WHEN orders.status = "Delivered" THEN order_items.quantity ELSE 0 END), 0) AS units_delivered,
                COALESCE(SUM(CASE WHEN orders.status = "Returned" THEN order_items.quantity ELSE 0 END), 0) AS units_returned,
                COALESCE(SUM(CASE WHEN orders.status = "Delivered" THEN order_items.total_price ELSE 0 END), 0) AS revenue,
                COALESCE(SUM(CASE WHEN orders.status = "Delivered" THEN (order_items.unit_cost * order_items.quantity) ELSE 0 END), 0) AS cogs
            ')
            ->groupBy('order_items.product_id')
            ->orderByDesc('revenue')
            ->limit($limit)
            ->get()
            ->map(function ($r) {
                $r->gross_profit = (float) $r->revenue - (float) $r->cogs;
                $r->return_rate = (int) $r->units_total > 0
                    ? round(((int) $r->units_returned / (int) $r->units_total) * 100, 1)
                    : 0;
                return $r;
            });

        return ['from' => $from, 'to' => $to, 'rows' => $rows];
    }

    /**
     * Products that sell but lose money (cogs ≥ revenue, or negative gross).
     */
    public function unprofitableProducts(?string $from, ?string $to): array
    {
        $data = $this->productProfitability($from, $to, 999);
        $bad = collect($data['rows'])
            ->filter(fn ($r) => (float) $r->revenue > 0 && (float) $r->gross_profit <= 0)
            ->values();

        return ['from' => $data['from'], 'to' => $data['to'], 'rows' => $bad];
    }

    /**
     * Inventory snapshot: per-product on-hand totals + reorder flags.
     * Uses InventoryService inputs computed inline to keep this fast.
     */
    public function inventory(): array
    {
        $rows = DB::select('
            SELECT p.id, p.sku, p.name, p.reorder_level,
                COALESCE(SUM(CASE WHEN im.movement_type IN ("Purchase","Return To Stock","Opening Balance","Transfer In","Adjustment","Stock Count Correction","Ship","Return Damaged","Transfer Out") THEN im.quantity ELSE 0 END), 0) AS on_hand,
                COALESCE(SUM(CASE WHEN im.movement_type = "Reserve" THEN im.quantity ELSE 0 END), 0) AS reserved_in,
                COALESCE(SUM(CASE WHEN im.movement_type = "Release Reservation" THEN im.quantity ELSE 0 END), 0) AS reserved_out
            FROM products p
            LEFT JOIN inventory_movements im ON im.product_id = p.id
            WHERE p.deleted_at IS NULL AND p.status = "Active"
            GROUP BY p.id, p.sku, p.name, p.reorder_level
            ORDER BY p.name
            LIMIT 200
        ');

        foreach ($rows as $r) {
            $r->reserved = max(0, (int) $r->reserved_in - (int) $r->reserved_out);
            $r->available = (int) $r->on_hand - (int) $r->reserved;
            $r->is_low = (int) $r->available <= (int) $r->reorder_level;
        }

        return ['rows' => $rows];
    }

    /**
     * Stock forecast: how many days of stock left at recent burn rate.
     * "Burn rate" = avg daily units shipped over the last 30 days.
     */
    public function stockForecast(int $lookbackDays = 30, int $limit = 60): array
    {
        $since = now()->subDays($lookbackDays)->toDateTimeString();

        $burn = DB::table('inventory_movements')
            ->selectRaw('product_id, SUM(ABS(quantity)) AS units_out')
            ->where('movement_type', 'Ship')
            ->where('created_at', '>=', $since)
            ->groupBy('product_id')
            ->get()
            ->keyBy('product_id');

        $inv = collect($this->inventory()['rows']);

        $rows = $inv->map(function ($r) use ($burn, $lookbackDays) {
            $totalOut = (int) ($burn[$r->id]->units_out ?? 0);
            $perDay = $totalOut > 0 ? $totalOut / $lookbackDays : 0;
            $r->daily_burn = round($perDay, 2);
            $r->days_left = $perDay > 0 ? floor((int) $r->available / $perDay) : null;
            return $r;
        });

        $rows = $rows
            ->filter(fn ($r) => (float) $r->daily_burn > 0)
            ->sortBy(fn ($r) => $r->days_left ?? 99999)
            ->take($limit)
            ->values();

        return ['lookback_days' => $lookbackDays, 'rows' => $rows];
    }

    /**
     * Shipping performance: per-carrier delivery rate, return rate, avg days.
     */
    public function shippingPerformance(?string $from, ?string $to): array
    {
        ['from' => $from, 'to' => $to] = $this->dateRange($from, $to);

        // `delayed` / `returned` are MySQL reserved words — alias to safe
        // names then map back to what the UI expects.
        $rows = DB::select('
            SELECT sc.id, sc.name,
                COUNT(s.id) AS shipments,
                SUM(CASE WHEN s.shipping_status = "Delivered" THEN 1 ELSE 0 END) AS n_delivered,
                SUM(CASE WHEN s.shipping_status = "Returned" THEN 1 ELSE 0 END) AS n_returned,
                SUM(CASE WHEN s.shipping_status = "Delayed" THEN 1 ELSE 0 END) AS n_delayed,
                AVG(CASE WHEN s.delivered_at IS NOT NULL AND s.assigned_at IS NOT NULL
                    THEN TIMESTAMPDIFF(HOUR, s.assigned_at, s.delivered_at)/24 END) AS avg_days
            FROM shipping_companies sc
            LEFT JOIN shipments s ON s.shipping_company_id = sc.id
                AND s.created_at BETWEEN ? AND ?
            GROUP BY sc.id, sc.name
            ORDER BY shipments DESC
        ', [$from . ' 00:00:00', $to . ' 23:59:59']);

        foreach ($rows as $r) {
            $shipments = (int) $r->shipments;
            $r->delivered = (int) $r->n_delivered;
            $r->returned = (int) $r->n_returned;
            $r->delayed = (int) $r->n_delayed;
            $r->delivery_rate = $shipments > 0 ? round((int) $r->delivered / $shipments * 100, 1) : 0;
            $r->return_rate = $shipments > 0 ? round((int) $r->returned / $shipments * 100, 1) : 0;
            $r->avg_days = $r->avg_days !== null ? round((float) $r->avg_days, 1) : null;
        }

        return ['from' => $from, 'to' => $to, 'rows' => $rows];
    }

    /**
     * Collections: due vs collected, broken down by status + carrier.
     */
    public function collections(?string $from, ?string $to): array
    {
        ['from' => $from, 'to' => $to] = $this->dateRange($from, $to);

        $totals = Collection::query()
            ->whereBetween('created_at', [$from . ' 00:00:00', $to . ' 23:59:59'])
            ->selectRaw('
                COALESCE(SUM(amount_due), 0) AS total_due,
                COALESCE(SUM(amount_collected), 0) AS total_collected,
                SUM(CASE WHEN collection_status IN ("Not Collected","Partially Collected","Pending Settlement") THEN 1 ELSE 0 END) AS outstanding_count,
                COUNT(*) AS total_count
            ')
            ->first();

        $byStatus = Collection::query()
            ->whereBetween('created_at', [$from . ' 00:00:00', $to . ' 23:59:59'])
            ->selectRaw('collection_status, COUNT(*) AS count, COALESCE(SUM(amount_due),0) AS due, COALESCE(SUM(amount_collected),0) AS collected')
            ->groupBy('collection_status')
            ->get();

        return ['from' => $from, 'to' => $to, 'totals' => $totals, 'by_status' => $byStatus];
    }

    public function returns(?string $from, ?string $to): array
    {
        ['from' => $from, 'to' => $to] = $this->dateRange($from, $to);

        $totals = OrderReturn::query()
            ->whereBetween('created_at', [$from . ' 00:00:00', $to . ' 23:59:59'])
            ->selectRaw('
                COUNT(*) AS total,
                SUM(CASE WHEN return_status = "Restocked" THEN 1 ELSE 0 END) AS restocked,
                SUM(CASE WHEN return_status = "Damaged" THEN 1 ELSE 0 END) AS damaged,
                COALESCE(SUM(refund_amount), 0) AS refund_total,
                COALESCE(SUM(shipping_loss_amount), 0) AS shipping_loss_total
            ')
            ->first();

        $byReason = OrderReturn::query()
            ->join('return_reasons', 'return_reasons.id', '=', 'returns.return_reason_id')
            ->whereBetween('returns.created_at', [$from . ' 00:00:00', $to . ' 23:59:59'])
            ->selectRaw('return_reasons.name AS reason, COUNT(returns.id) AS count')
            ->groupBy('return_reasons.name')
            ->orderByDesc('count')
            ->get();

        return ['from' => $from, 'to' => $to, 'totals' => $totals, 'by_reason' => $byReason];
    }

    /**
     * Marketer performance: orders/profit/return-rate per marketer.
     */
    public function marketers(?string $from, ?string $to): array
    {
        ['from' => $from, 'to' => $to] = $this->dateRange($from, $to);

        $rows = DB::select('
            SELECT m.id, m.code, u.name AS marketer_name,
                COUNT(o.id) AS orders,
                SUM(CASE WHEN o.status = "Delivered" THEN 1 ELSE 0 END) AS delivered,
                SUM(CASE WHEN o.status = "Returned" THEN 1 ELSE 0 END) AS returned,
                COALESCE(SUM(CASE WHEN o.status = "Delivered" THEN o.total_amount ELSE 0 END), 0) AS revenue,
                COALESCE((SELECT SUM(net_profit) FROM marketer_transactions
                    WHERE marketer_id = m.id AND transaction_type = "Earned Profit" AND status = "Approved"
                    AND created_at BETWEEN ? AND ?), 0) AS earned
            FROM marketers m
            INNER JOIN users u ON u.id = m.user_id
            LEFT JOIN orders o ON o.marketer_id = m.id
                AND o.created_at BETWEEN ? AND ?
            GROUP BY m.id, m.code, u.name
            ORDER BY revenue DESC
        ', [$from . ' 00:00:00', $to . ' 23:59:59', $from . ' 00:00:00', $to . ' 23:59:59']);

        foreach ($rows as $r) {
            $r->return_rate = (int) $r->orders > 0 ? round(((int) $r->returned / (int) $r->orders) * 100, 1) : 0;
        }

        return ['from' => $from, 'to' => $to, 'rows' => $rows];
    }

    /**
     * Staff performance: per-user, confirmed/delivered counts, target progress.
     */
    public function staff(?string $from, ?string $to): array
    {
        ['from' => $from, 'to' => $to] = $this->dateRange($from, $to);

        $rows = DB::select('
            SELECT u.id, u.name, r.name AS role,
                SUM(CASE WHEN osh.new_status = "Confirmed" AND osh.changed_by = u.id THEN 1 ELSE 0 END) AS confirmed_orders,
                SUM(CASE WHEN osh.new_status = "Shipped" AND osh.changed_by = u.id THEN 1 ELSE 0 END) AS shipped_orders,
                SUM(CASE WHEN osh.new_status = "Delivered" AND osh.changed_by = u.id THEN 1 ELSE 0 END) AS delivered_orders
            FROM users u
            LEFT JOIN roles r ON r.id = u.role_id
            LEFT JOIN order_status_history osh ON osh.changed_by = u.id
                AND osh.created_at BETWEEN ? AND ?
            WHERE u.deleted_at IS NULL AND u.status = "Active"
            GROUP BY u.id, u.name, r.name
            ORDER BY (confirmed_orders + shipped_orders + delivered_orders) DESC
        ', [$from . ' 00:00:00', $to . ' 23:59:59']);

        return ['from' => $from, 'to' => $to, 'rows' => $rows];
    }

    /**
     * Ad campaigns rollup with derived metrics.
     */
    public function ads(?string $from, ?string $to): array
    {
        ['from' => $from, 'to' => $to] = $this->dateRange($from, $to);

        $rows = AdCampaign::query()
            ->where(function ($q) use ($from, $to) {
                $q->whereBetween('start_date', [$from, $to])
                    ->orWhereBetween('end_date', [$from, $to])
                    ->orWhereNull('end_date');
            })
            ->orderByDesc('roas')
            ->get([
                'id', 'name', 'platform', 'status',
                'spend', 'revenue', 'orders_count',
                'delivered_orders_count', 'returned_orders_count',
                'gross_profit', 'net_profit', 'cost_per_order', 'roas',
            ]);

        $totals = [
            'spend' => $rows->sum(fn ($r) => (float) $r->spend),
            'revenue' => $rows->sum(fn ($r) => (float) $r->revenue),
            'net' => $rows->sum(fn ($r) => (float) $r->net_profit),
        ];

        return ['from' => $from, 'to' => $to, 'rows' => $rows, 'totals' => $totals];
    }

    /**
     * Cash flow: inflows (delivered orders) vs outflows (expenses + supplier
     * payments + marketer payouts). Daily series plus totals.
     */
    public function cashFlow(?string $from, ?string $to): array
    {
        ['from' => $from, 'to' => $to] = $this->dateRange($from, $to);

        $inflows = (float) Order::query()
            ->where('status', 'Delivered')
            ->whereBetween('delivered_at', [$from . ' 00:00:00', $to . ' 23:59:59'])
            ->sum('total_amount');

        $expensesOut = (float) Expense::query()
            ->whereBetween('expense_date', [$from, $to])
            ->sum('amount');

        $supplierOut = (float) DB::table('supplier_payments')
            ->whereBetween('payment_date', [$from, $to])
            ->sum('amount');

        $marketerOut = (float) DB::table('marketer_transactions')
            ->where('transaction_type', 'Payout')
            ->where('status', 'Paid')
            ->whereBetween('created_at', [$from . ' 00:00:00', $to . ' 23:59:59'])
            ->sum('net_profit');

        $totalOut = $expensesOut + $supplierOut + $marketerOut;

        return [
            'from' => $from, 'to' => $to,
            'inflows' => round($inflows, 2),
            'outflows' => [
                'expenses' => round($expensesOut, 2),
                'supplier_payments' => round($supplierOut, 2),
                'marketer_payouts' => round($marketerOut, 2),
                'total' => round($totalOut, 2),
            ],
            'net' => round($inflows - $totalOut, 2),
        ];
    }
}
