<?php

namespace App\Services;

use App\Models\Collection;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\Ticket;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Aggregates KPIs, chart series, and short attention lists for the admin
 * dashboard. Extracted from DashboardController so the controller stays
 * thin and the metrics are independently testable.
 *
 * Every aggregate below is a single SQL query (no N+1). The low-stock
 * base query is memoised per instance because it is consumed by three
 * different blocks (KPI count, table list, alerts count) and rebuilding
 * it would scan inventory_movements three times.
 */
class DashboardMetricsService
{
    /**
     * Memoised low-stock count. The same value is consumed by both the
     * KPI block and the alerts block; without this it would run the
     * heavy SUM-CASE COUNT twice per request.
     */
    private ?int $lowStockCount = null;

    /* ────────────────────── KPIs ────────────────────── */

    /**
     * @return array<string, int|float>
     */
    public function kpis(CarbonImmutable $today, CarbonImmutable $yesterday, CarbonImmutable $monthStart): array
    {
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
        $activeShipments = Shipment::query()
            ->whereIn('shipping_status', Shipment::ACTIVE_STATUSES)
            ->count();

        $delivered = $this->deliveredCounts($today, $monthStart);

        $returnsToday = DB::table('returns')
            ->whereDate('created_at', $today)
            ->count();

        $collectionsTodayAgg = Collection::query()
            ->whereDate('settlement_date', $today)
            ->where('collection_status', 'Collected')
            ->selectRaw('COUNT(*) AS c, COALESCE(SUM(amount_collected),0) AS s')
            ->first();

        $lowStockCount = $this->lowStockCount();

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

            'delivered_today' => $delivered['today'],
            'delivered_mtd' => $delivered['mtd'],

            'pending_orders' => $pendingOrders,
            'ready_to_pack' => $readyToPack,
            'ready_to_ship' => $readyToShip,
            'active_shipments' => $activeShipments,
            'delayed_shipments' => $delayedShipments,
            'returns_today' => $returnsToday,
            'low_stock_products' => $lowStockCount,
            'active_customers_this_month' => $activeCustomersThisMonth,
        ];
    }

    /**
     * Counts of delivered orders sourced from `orders.delivered_at` — the
     * canonical moment of delivery. NOT from status + created_at, which
     * would count orders created today that happen to be already
     * delivered (almost never what management wants).
     *
     * @return array{today:int, mtd:int}
     */
    public function deliveredCounts(CarbonImmutable $today, CarbonImmutable $monthStart): array
    {
        $todayCount = Order::query()
            ->whereNotNull('delivered_at')
            ->whereDate('delivered_at', $today)
            ->count();

        $mtdCount = Order::query()
            ->whereNotNull('delivered_at')
            ->where('delivered_at', '>=', $monthStart)
            ->count();

        return ['today' => $todayCount, 'mtd' => $mtdCount];
    }

    /**
     * Count of currently-active shipments — i.e. shipments that are still
     * in motion. Uses Shipment::ACTIVE_STATUSES so the definition stays
     * in one place.
     */
    public function activeShipmentsCount(): int
    {
        return Shipment::query()
            ->whereIn('shipping_status', Shipment::ACTIVE_STATUSES)
            ->count();
    }

    /**
     * Open tickets = open + in_progress. Closed tickets are excluded.
     */
    public function openTicketsCount(): int
    {
        return Ticket::query()
            ->whereIn('status', [Ticket::STATUS_OPEN, Ticket::STATUS_IN_PROGRESS])
            ->count();
    }

    /* ────────────────────── Charts ────────────────────── */

    /**
     * @return array<int, array{date:string, value:float}>
     */
    public function ordersTrend(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = Order::query()
            ->whereBetween('created_at', [$from->startOfDay(), $to->endOfDay()])
            ->whereNotIn('status', ['Cancelled'])
            ->selectRaw('DATE(created_at) AS d, COUNT(*) AS c')
            ->groupBy('d')
            ->pluck('c', 'd');

        return $this->fillDailySeries($from, $to, $rows);
    }

    /**
     * @return array<int, array{date:string, value:float}>
     */
    public function salesTrend(CarbonImmutable $from, CarbonImmutable $to): array
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
     * @return array<int, array{status:string, count:int}>
     */
    public function statusDistribution(CarbonImmutable $monthStart): array
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

    /**
     * Latest orders for the dashboard table.
     *
     * IMPORTANT: callers must gate this with `orders.view`. The service
     * does not enforce permissions itself — that belongs in the
     * controller layer, but never call this for an unauthorised user
     * since it would leak customer names and totals.
     *
     * @return array<int, array<string, mixed>>
     */
    public function latestOrders(int $limit = 8): array
    {
        return Order::query()
            ->latest('id')
            ->limit($limit)
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
     *
     * @return array<int, array<string, int|string>>
     */
    public function lowStockProducts(int $limit = 8): array
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
     * Active shipments currently flagged Delayed (full row, for the table).
     *
     * @return array<int, array<string, mixed>>
     */
    public function delayedShipments(int $limit = 8): array
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

    /* ────────────────────── Alerts ────────────────────── */

    /**
     * @return array<string, int>
     */
    public function alerts(): array
    {
        return [
            'delayed_shipments' => Shipment::where('shipping_status', 'Delayed')->count(),
            'low_stock_products' => $this->lowStockCount(),
            'returns_pending_inspection' => DB::table('returns')
                ->where('return_status', 'Pending')
                ->count(),
            'pending_collections' => Collection::where('collection_status', 'Not Collected')->count(),
            'pending_approvals' => DB::table('approval_requests')
                ->where('status', 'Pending')
                ->count(),
        ];
    }

    /* ────────────────────── Internals ────────────────────── */

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
     * Builds the low-stock query (no order/limit). Used by both the
     * count (memoised separately) and the limited table fetch.
     */
    private function lowStockBaseQuery(): \Illuminate\Database\Query\Builder
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
     * Memoised total count of low-stock products. Computed once per
     * service instance (= once per dashboard request).
     */
    private function lowStockCount(): int
    {
        if ($this->lowStockCount === null) {
            $this->lowStockCount = $this->lowStockBaseQuery()->count();
        }
        return $this->lowStockCount;
    }
}
