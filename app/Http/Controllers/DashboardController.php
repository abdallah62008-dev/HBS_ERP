<?php

namespace App\Http\Controllers;

use App\Models\Collection;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shipment;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin operational dashboard.
 *
 * Shows real KPIs, mini charts, and short attention tables for
 * super-admin / staff users. Marketer-role users are redirected to
 * the simplified /marketer/dashboard so they never see admin data.
 *
 * Every aggregate below is intentionally a single SQL query (no N+1):
 *   - status counts: GROUP BY status, single sweep over orders
 *   - chart series: GROUP BY DATE(...), single sweep per chart
 *   - low-stock list: derived from inventory_movements via existing
 *     SUM-CASE pattern (mirrors InventoryController) — one query
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        // Marketers are routed to their portal dashboard so they never
        // see admin metrics. Super-admin (debug/support) keeps the admin
        // dashboard.
        if ($user && $user->isMarketer() && ! $user->isSuperAdmin()) {
            return redirect()->route('marketer.dashboard');
        }

        $today = CarbonImmutable::today();
        $yesterday = $today->subDay();
        $monthStart = $today->startOfMonth();
        $weekStart = $today->subDays(6); // last 7 days incl. today

        return Inertia::render('Dashboard', [
            'kpis' => $this->kpis($today, $yesterday, $monthStart),
            'charts' => [
                'orders_trend' => $this->ordersTrend($weekStart, $today),
                'sales_trend' => $this->salesTrend($weekStart, $today),
                'status_distribution' => $this->statusDistribution($monthStart),
            ],
            'tables' => [
                'latest_orders' => $this->latestOrders(),
                'low_stock' => $this->lowStockProducts(),
                'delayed_shipments' => $this->delayedShipments(),
            ],
            'alerts' => $this->alerts(),
        ]);
    }

    /* ────────────────────── KPIs ────────────────────── */

    private function kpis(CarbonImmutable $today, CarbonImmutable $yesterday, CarbonImmutable $monthStart): array
    {
        // Single sweep over today's orders for count + revenue
        $todayAgg = Order::query()
            ->whereDate('created_at', $today)
            ->whereNotIn('status', ['Cancelled'])
            ->selectRaw('COUNT(*) AS c, COALESCE(SUM(total_amount),0) AS s')
            ->first();

        $yesterdayAgg = Order::query()
            ->whereDate('created_at', $yesterday)
            ->whereNotIn('status', ['Cancelled'])
            ->selectRaw('COUNT(*) AS c, COALESCE(SUM(total_amount),0) AS s')
            ->first();

        // Group operational pipeline statuses in one query
        $statusCounts = Order::query()
            ->whereIn('status', [
                'New', 'Pending Confirmation', 'Confirmed',
                'Ready to Pack', 'Packed', 'Ready to Ship',
            ])
            ->selectRaw('status, COUNT(*) AS c')
            ->groupBy('status')
            ->pluck('c', 'status');

        $pendingOrders = (int) ($statusCounts['New'] ?? 0)
            + (int) ($statusCounts['Pending Confirmation'] ?? 0)
            + (int) ($statusCounts['Confirmed'] ?? 0);
        $readyToPack = (int) ($statusCounts['Ready to Pack'] ?? 0);
        $readyToShip = (int) ($statusCounts['Packed'] ?? 0)
            + (int) ($statusCounts['Ready to Ship'] ?? 0);

        $delayedShipments = Shipment::where('shipping_status', 'Delayed')->count();

        $returnsToday = DB::table('returns')
            ->whereDate('created_at', $today)
            ->count();

        $collectionsTodayAgg = Collection::query()
            ->whereDate('settlement_date', $today)
            ->where('collection_status', 'Collected')
            ->selectRaw('COUNT(*) AS c, COALESCE(SUM(amount_collected),0) AS s')
            ->first();

        $lowStockCount = $this->lowStockBaseQuery()->count();

        $activeCustomersThisMonth = Order::query()
            ->where('created_at', '>=', $monthStart)
            ->whereNotIn('status', ['Cancelled'])
            ->distinct('customer_id')
            ->count('customer_id');

        return [
            'orders_today' => (int) $todayAgg->c,
            'orders_yesterday' => (int) $yesterdayAgg->c,

            'sales_today' => (float) $todayAgg->s,
            'sales_yesterday' => (float) $yesterdayAgg->s,

            'collections_today_count' => (int) $collectionsTodayAgg->c,
            'collections_today_amount' => (float) $collectionsTodayAgg->s,

            'pending_orders' => $pendingOrders,
            'ready_to_pack' => $readyToPack,
            'ready_to_ship' => $readyToShip,
            'delayed_shipments' => $delayedShipments,
            'returns_today' => $returnsToday,
            'low_stock_products' => $lowStockCount,
            'active_customers_this_month' => $activeCustomersThisMonth,
        ];
    }

    /* ────────────────────── Charts ────────────────────── */

    private function ordersTrend(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = Order::query()
            ->whereBetween('created_at', [$from->startOfDay(), $to->endOfDay()])
            ->whereNotIn('status', ['Cancelled'])
            ->selectRaw('DATE(created_at) AS d, COUNT(*) AS c')
            ->groupBy('d')
            ->pluck('c', 'd');

        return $this->fillDailySeries($from, $to, $rows);
    }

    private function salesTrend(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = Order::query()
            ->whereBetween('created_at', [$from->startOfDay(), $to->endOfDay()])
            ->whereNotIn('status', ['Cancelled'])
            ->selectRaw('DATE(created_at) AS d, COALESCE(SUM(total_amount),0) AS s')
            ->groupBy('d')
            ->pluck('s', 'd');

        return $this->fillDailySeries($from, $to, $rows);
    }

    /**
     * Pads a date-keyed map with zeros for missing days so the chart
     * always renders a continuous N-day series.
     *
     * @param  \Illuminate\Support\Collection<string, mixed>  $rows
     * @return array<int, array{date:string, value:float}>
     */
    private function fillDailySeries(CarbonImmutable $from, CarbonImmutable $to, $rows): array
    {
        $series = [];
        $cursor = $from;
        while ($cursor->lte($to)) {
            $key = $cursor->toDateString();
            $series[] = [
                'date' => $key,
                'value' => (float) ($rows[$key] ?? 0),
            ];
            $cursor = $cursor->addDay();
        }
        return $series;
    }

    /**
     * Status distribution for the current month — used for the donut/bar.
     *
     * @return array<int, array{status:string, count:int}>
     */
    private function statusDistribution(CarbonImmutable $monthStart): array
    {
        $rows = Order::query()
            ->where('created_at', '>=', $monthStart)
            ->selectRaw('status, COUNT(*) AS c')
            ->groupBy('status')
            ->orderByDesc('c')
            ->get();

        return $rows->map(fn ($r) => [
            'status' => $r->status,
            'count' => (int) $r->c,
        ])->all();
    }

    /* ────────────────────── Tables ────────────────────── */

    private function latestOrders(): array
    {
        return Order::query()
            ->latest('id')
            ->limit(8)
            ->get([
                'id', 'order_number', 'customer_name', 'status',
                'total_amount', 'collection_status', 'created_at',
            ])
            ->map(fn ($o) => [
                'id' => $o->id,
                'order_number' => $o->order_number,
                'customer_name' => $o->customer_name,
                'status' => $o->status,
                'total_amount' => (float) $o->total_amount,
                'collection_status' => $o->collection_status,
                'created_at' => $o->created_at?->toDateTimeString(),
            ])
            ->all();
    }

    /**
     * Products whose on-hand has fallen at or below their reorder_level.
     * Single query that joins products with the SUM-CASE inventory pattern.
     */
    private function lowStockProducts(int $limit = 8): array
    {
        return $this->lowStockBaseQuery()
            ->orderBy('on_hand')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => [
                'product_id' => (int) $r->product_id,
                'sku' => $r->sku,
                'name' => $r->name,
                'on_hand' => (int) $r->on_hand,
                'reserved' => (int) $r->reserved,
                'available' => max(0, (int) $r->on_hand - (int) $r->reserved),
                'reorder_level' => (int) $r->reorder_level,
            ])
            ->all();
    }

    /**
     * Builds the low-stock query (no order/limit). Used by both the
     * count KPI and the table.
     */
    private function lowStockBaseQuery()
    {
        $stockTypes = "'Purchase','Return To Stock','Opening Balance','Transfer In','Adjustment','Stock Count Correction','Ship','Return Damaged','Transfer Out'";

        return DB::table('products')
            ->leftJoin('inventory_movements', 'products.id', '=', 'inventory_movements.product_id')
            ->whereNull('products.deleted_at')
            ->where('products.status', 'Active')
            ->selectRaw("
                products.id AS product_id,
                products.sku,
                products.name,
                products.reorder_level,
                COALESCE(SUM(CASE WHEN inventory_movements.movement_type IN ($stockTypes) THEN inventory_movements.quantity ELSE 0 END), 0) AS on_hand,
                COALESCE(SUM(CASE WHEN inventory_movements.movement_type = 'Reserve' THEN inventory_movements.quantity ELSE 0 END), 0)
                  - COALESCE(SUM(CASE WHEN inventory_movements.movement_type = 'Release Reservation' THEN inventory_movements.quantity ELSE 0 END), 0) AS reserved
            ")
            ->groupBy('products.id', 'products.sku', 'products.name', 'products.reorder_level')
            ->havingRaw('on_hand <= products.reorder_level');
    }

    /**
     * Active shipments currently flagged Delayed.
     */
    private function delayedShipments(int $limit = 8): array
    {
        return Shipment::query()
            ->where('shipping_status', 'Delayed')
            ->with([
                'order:id,order_number,customer_name,created_at',
                'shippingCompany:id,name',
            ])
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(function ($s) {
                $delayDays = $s->order?->created_at
                    ? (int) $s->order->created_at->diffInDays(now())
                    : null;
                return [
                    'shipment_id' => $s->id,
                    'order_id' => $s->order?->id,
                    'order_number' => $s->order?->order_number,
                    'customer_name' => $s->order?->customer_name,
                    'carrier' => $s->shippingCompany?->name,
                    'delay_days' => $delayDays,
                    'status' => $s->shipping_status,
                ];
            })
            ->all();
    }

    /* ────────────────────── Alerts panel ────────────────────── */

    private function alerts(): array
    {
        return [
            'delayed_shipments' => Shipment::where('shipping_status', 'Delayed')->count(),
            'low_stock_products' => $this->lowStockBaseQuery()->count(),
            'returns_pending_inspection' => DB::table('returns')
                ->where('return_status', 'Pending')
                ->count(),
            'pending_collections' => Collection::where('collection_status', 'Not Collected')->count(),
            'pending_approvals' => DB::table('approval_requests')
                ->where('status', 'Pending')
                ->count(),
        ];
    }
}
