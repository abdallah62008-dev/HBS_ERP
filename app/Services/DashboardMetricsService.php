<?php

namespace App\Services;

use App\Models\Collection;
use App\Models\FiscalYear;
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
 * Every aggregate below is a single SQL query (no N+1). The low-stock and
 * out-of-stock counts are memoised per instance — they each scan
 * inventory_movements and are consumed by multiple blocks.
 *
 * Period framing: callers pass a period key ('today' | '7d' | 'mtd' |
 * 'fytd') and the service resolves it to a [from, to, label] window.
 * Period-respecting metrics (orders, sales, delivered, returns,
 * collections) use that window. Point-in-time metrics (pending orders,
 * active shipments, low stock, etc.) ignore the period because they
 * describe current state, not a date range.
 */
class DashboardMetricsService
{
    /** Allowed period selector keys. The default is `today`. */
    public const PERIOD_KEYS = ['today', '7d', 'mtd', 'fytd'];

    /**
     * Memoised low-stock count. Same value is consumed by both the KPI
     * block and the alerts block — without this it would run the heavy
     * SUM-CASE COUNT twice per request.
     */
    private ?int $lowStockCount = null;

    /** Memoised out-of-stock count (same rationale as low stock). */
    private ?int $outOfStockCount = null;

    /* ────────────────────── Period range ────────────────────── */

    /**
     * Resolve a period selector key into a date window.
     *
     * - today: [today, today]
     * - 7d:    [today-6 .. today] (rolling 7 days incl. today)
     * - mtd:   [startOfMonth .. today]
     * - fytd:  [start of the currently-open FiscalYear .. today]
     *
     * Unknown keys fall back to `today` so a malformed query param can
     * never crash the dashboard.
     *
     * @return array{key:string, label:string, from:CarbonImmutable, to:CarbonImmutable}
     */
    public function periodRange(string $key): array
    {
        $today = CarbonImmutable::today();
        $key = in_array($key, self::PERIOD_KEYS, true) ? $key : 'today';

        return match ($key) {
            '7d' => [
                'key' => '7d',
                'label' => '7d',
                'from' => $today->subDays(6),
                'to' => $today,
            ],
            'mtd' => [
                'key' => 'mtd',
                'label' => 'MTD',
                'from' => $today->startOfMonth(),
                'to' => $today,
            ],
            'fytd' => [
                'key' => 'fytd',
                'label' => 'FYTD',
                'from' => $this->currentFiscalYearStart($today),
                'to' => $today,
            ],
            default => [
                'key' => 'today',
                'label' => 'Today',
                'from' => $today,
                'to' => $today,
            ],
        };
    }

    /**
     * Start of the currently-open fiscal year, or year-start as a safe
     * fallback when no Open fiscal year row exists (e.g. seed data not
     * yet bootstrapped in some test environments).
     */
    private function currentFiscalYearStart(CarbonImmutable $today): CarbonImmutable
    {
        $fy = FiscalYear::query()
            ->where('status', 'Open')
            ->orderByDesc('start_date')
            ->first();

        if ($fy && $fy->start_date) {
            return CarbonImmutable::parse($fy->start_date)->startOfDay();
        }

        return $today->startOfYear();
    }

    /* ────────────────────── KPIs ────────────────────── */

    /**
     * Period-respecting KPIs for the Today Snapshot tiles.
     *
     * Returns counts/sums for the supplied window. The frontend renders
     * a yesterday-comparison delta only when the selected period is
     * `today`; for other periods the comparison values are absent.
     *
     * @return array<string, int|float>
     */
    public function periodTotals(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $orderAgg = Order::query()
            ->whereBetween('created_at', [$from->startOfDay(), $to->endOfDay()])
            ->whereNotIn('status', ['Cancelled'])
            ->selectRaw('COUNT(*) AS c, COALESCE(SUM(total_amount),0) AS s')
            ->first();

        $deliveredInPeriod = Order::query()
            ->whereNotNull('delivered_at')
            ->whereBetween('delivered_at', [$from->startOfDay(), $to->endOfDay()])
            ->count();

        $returnsInPeriod = DB::table('returns')
            ->whereBetween('created_at', [$from->startOfDay(), $to->endOfDay()])
            ->count();

        $collectionsAgg = Collection::query()
            ->whereBetween('settlement_date', [$from->toDateString(), $to->toDateString()])
            ->where('collection_status', 'Collected')
            ->selectRaw('COUNT(*) AS c, COALESCE(SUM(amount_collected),0) AS s')
            ->first();

        return [
            'orders' => (int) $orderAgg->c,
            'sales' => (float) $orderAgg->s,
            'delivered' => $deliveredInPeriod,
            'returns' => $returnsInPeriod,
            'collections_count' => (int) $collectionsAgg->c,
            'collections_amount' => (float) $collectionsAgg->s,
        ];
    }

    /**
     * Point-in-time operational counts. These describe current state and
     * intentionally do NOT depend on the period selector.
     *
     * @return array<string, int>
     */
    public function operationalCounts(CarbonImmutable $monthStart): array
    {
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
        $activeShipments = $this->activeShipmentsCount();

        $activeCustomersThisMonth = Order::query()
            ->where('created_at', '>=', $monthStart)
            ->whereNotIn('status', ['Cancelled'])
            ->distinct('customer_id')
            ->count('customer_id');

        return [
            'pending_orders' => $pendingOrders,
            'ready_to_pack' => $readyToPack,
            'ready_to_ship' => $readyToShip,
            'active_shipments' => $activeShipments,
            'delayed_shipments' => $delayedShipments,
            'low_stock_products' => $this->lowStockCount(),
            'active_customers_this_month' => $activeCustomersThisMonth,
        ];
    }

    /**
     * Counts of delivered orders sourced from `orders.delivered_at` — the
     * canonical moment of delivery.
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
     * Count of currently-active shipments using Shipment::ACTIVE_STATUSES.
     */
    public function activeShipmentsCount(): int
    {
        return Shipment::query()
            ->whereIn('shipping_status', Shipment::ACTIVE_STATUSES)
            ->count();
    }

    /**
     * Open tickets = open + in_progress.
     */
    public function openTicketsCount(): int
    {
        return Ticket::query()
            ->whereIn('status', [Ticket::STATUS_OPEN, Ticket::STATUS_IN_PROGRESS])
            ->count();
    }

    /**
     * Delivery rate (MTD) = delivered / (delivered + returned + cancelled).
     *
     * Cohort: orders CREATED this month. Numerator: those with
     * status='Delivered'. Denominator: those with status in
     * ('Delivered', 'Returned', 'Cancelled') — i.e. orders that have
     * reached a terminal state. Open orders are excluded from both.
     *
     * Returns `rate=null` when the denominator is zero so the frontend
     * can render a dash instead of a misleading 0%.
     *
     * @return array{rate:float|null, delivered:int, resolved:int}
     */
    public function deliveryRateMtd(CarbonImmutable $monthStart): array
    {
        $row = Order::query()
            ->where('created_at', '>=', $monthStart)
            ->whereIn('status', ['Delivered', 'Returned', 'Cancelled'])
            ->selectRaw("
                COUNT(*) AS resolved,
                SUM(CASE WHEN status = 'Delivered' THEN 1 ELSE 0 END) AS delivered
            ")
            ->first();

        $resolved = (int) ($row->resolved ?? 0);
        $delivered = (int) ($row->delivered ?? 0);
        $rate = $resolved > 0 ? round(($delivered / $resolved) * 100, 1) : null;

        return [
            'rate' => $rate,
            'delivered' => $delivered,
            'resolved' => $resolved,
        ];
    }

    /**
     * Average order value (MTD) = SUM(total_amount) / COUNT(orders).
     * Cancelled orders are excluded. Returns 0.0 (not null) when no
     * orders exist so the currency formatter renders cleanly.
     *
     * @return array{avg:float, count:int, total:float}
     */
    public function avgOrderValueMtd(CarbonImmutable $monthStart): array
    {
        $row = Order::query()
            ->where('created_at', '>=', $monthStart)
            ->whereNotIn('status', ['Cancelled'])
            ->selectRaw('COUNT(*) AS c, COALESCE(SUM(total_amount),0) AS s')
            ->first();

        $count = (int) $row->c;
        $total = (float) $row->s;
        $avg = $count > 0 ? $total / $count : 0.0;

        return ['avg' => $avg, 'count' => $count, 'total' => $total];
    }

    /**
     * Out-of-stock product count. Uses the same SUM-CASE inventory pattern
     * as low-stock but the HAVING clause checks `on_hand <= 0`.
     */
    public function outOfStockCount(): int
    {
        if ($this->outOfStockCount !== null) {
            return $this->outOfStockCount;
        }

        $stockTypes = "'Purchase','Return To Stock','Opening Balance','Transfer In','Adjustment','Stock Count Correction','Ship','Return Damaged','Transfer Out'";

        return $this->outOfStockCount = DB::table('products')
            ->leftJoin('inventory_movements', 'products.id', '=', 'inventory_movements.product_id')
            ->whereNull('products.deleted_at')
            ->where('products.status', 'Active')
            ->selectRaw("
                products.id AS product_id,
                COALESCE(SUM(CASE WHEN inventory_movements.movement_type IN ($stockTypes) THEN inventory_movements.quantity ELSE 0 END), 0) AS on_hand
            ")
            ->groupBy('products.id')
            ->havingRaw('on_hand <= 0')
            ->count();
    }

    /**
     * Expenses total within the supplied window. The `expenses` table has
     * no status column — every (non-soft-deleted) row is a real expense.
     */
    public function expensesTotal(CarbonImmutable $from, CarbonImmutable $to): float
    {
        return (float) DB::table('expenses')
            ->whereBetween('expense_date', [$from->toDateString(), $to->toDateString()])
            ->whereNull('deleted_at')
            ->sum('amount');
    }

    /**
     * Compact distribution of every shipment by its current status.
     * Statuses with zero shipments are omitted; the frontend can fill
     * gaps if it needs the full Shipment::STATUSES set.
     *
     * @return array<int, array{status:string, count:int}>
     */
    public function shipmentsByStatus(): array
    {
        return Shipment::query()
            ->selectRaw('shipping_status AS status, COUNT(*) AS c')
            ->groupBy('shipping_status')
            ->orderByDesc('c')
            ->get()
            ->map(fn ($r) => [
                'status' => $r->status,
                'count' => (int) $r->c,
            ])
            ->all();
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
     * does not enforce permissions itself.
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
            'out_of_stock_products' => $this->outOfStockCount(),
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
