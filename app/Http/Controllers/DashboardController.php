<?php

namespace App\Http\Controllers;

use App\Services\DashboardMetricsService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin operational dashboard.
 *
 * Shows KPIs, mini charts, and short attention tables for super-admin /
 * staff users. Marketer-role users are redirected to /marketer/dashboard
 * so they never see admin data.
 *
 * Period framing: the dashboard accepts `?period=today|7d|mtd|fytd` and
 * applies it to the Today Snapshot tiles. Aggregate MTD-named metrics
 * (Delivery Rate MTD, Avg Order Value MTD, Expenses MTD) keep their
 * MTD framing regardless of the selector — the selector only re-frames
 * the period-respecting tiles. Point-in-time counts (pending orders,
 * active shipments, low stock, …) ignore the selector by design.
 *
 * Permission gating: latest_orders, open_tickets, expenses_mtd,
 * out_of_stock, avg_order_value_mtd, delivery_rate_mtd, and
 * shipments_by_status are each emitted only when the user has the
 * corresponding permission. The frontend mirrors these gates for UI
 * but the backend is the source of truth — never rely on UI hiding
 * for sensitive data.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request, DashboardMetricsService $metrics): Response|RedirectResponse
    {
        $user = $request->user();

        if ($user && $user->isMarketer() && ! $user->isSuperAdmin()) {
            return redirect()->route('marketer.dashboard');
        }

        $today = CarbonImmutable::today();
        $yesterday = $today->subDay();
        $monthStart = $today->startOfMonth();
        $weekStart = $today->subDays(6); // last 7 days incl. today (trend charts only)

        $period = $metrics->periodRange((string) $request->query('period', 'today'));

        $can = [
            'orders' => (bool) $user?->hasPermission('orders.view'),
            'tickets' => (bool) $user?->hasPermission('tickets.view'),
            'shipping' => (bool) $user?->hasPermission('shipping.view'),
            'inventory' => (bool) $user?->hasPermission('inventory.view'),
            'expenses' => (bool) $user?->hasPermission('expenses.view'),
            'audit' => (bool) $user?->hasPermission('audit_logs.view'),
        ];

        $periodTotals = $metrics->periodTotals($period['from'], $period['to']);

        // Yesterday-comparison delta only makes sense when period=today.
        $periodCompare = $period['key'] === 'today'
            ? $metrics->periodTotals($yesterday, $yesterday)
            : null;

        $operational = $metrics->operationalCounts($monthStart);
        $delivered = $metrics->deliveredCounts($today, $monthStart);

        $kpis = array_merge(
            [
                'orders_period' => $periodTotals['orders'],
                'sales_period' => $periodTotals['sales'],
                'delivered_period' => $periodTotals['delivered'],
                'returns_period' => $periodTotals['returns'],
                'collections_period_count' => $periodTotals['collections_count'],
                'collections_period_amount' => $periodTotals['collections_amount'],
                'delivered_today' => $delivered['today'],
                'delivered_mtd' => $delivered['mtd'],
            ],
            $operational,
        );

        if ($periodCompare !== null) {
            $kpis['orders_compare'] = $periodCompare['orders'];
            $kpis['sales_compare'] = $periodCompare['sales'];
        }

        if ($can['orders']) {
            $rate = $metrics->deliveryRateMtd($monthStart);
            $kpis['delivery_rate_mtd'] = $rate['rate'];
            $kpis['delivery_rate_mtd_delivered'] = $rate['delivered'];
            $kpis['delivery_rate_mtd_resolved'] = $rate['resolved'];

            $aov = $metrics->avgOrderValueMtd($monthStart);
            $kpis['avg_order_value_mtd'] = $aov['avg'];
            $kpis['avg_order_value_mtd_count'] = $aov['count'];
        }

        if ($can['inventory']) {
            $kpis['out_of_stock'] = $metrics->outOfStockCount();
        }

        if ($can['expenses']) {
            $kpis['expenses_mtd'] = $metrics->expensesTotal($monthStart, $today);
        }

        if ($can['tickets']) {
            $kpis['open_tickets'] = $metrics->openTicketsCount();
        }

        $widgets = [];
        if ($can['shipping']) {
            $widgets['shipments_by_status'] = $metrics->shipmentsByStatus();
        }

        $tables = [
            // latest_orders carries customer names + totals + statuses, so
            // it MUST be gated server-side. Phase 1 fix preserved.
            'latest_orders' => $can['orders'] ? $metrics->latestOrders() : [],
            'low_stock' => $metrics->lowStockProducts(),
            'delayed_shipments' => $metrics->delayedShipments(),
        ];

        // Audit anomalies tile: intentionally NOT implemented in Phase 2.
        // The audit_logs table has no severity / event_type / failed flag,
        // and AuditLogService is caller-driven with no canonical
        // "permission_denied" or "login_failed" log point. Surfacing
        // anomalies would require fabricating signal. Deferred until a
        // structured audit-event taxonomy exists.

        return Inertia::render('Dashboard', [
            'period' => [
                'value' => $period['key'],
                'label' => $period['label'],
                'from' => $period['from']->toDateString(),
                'to' => $period['to']->toDateString(),
                'options' => DashboardMetricsService::PERIOD_KEYS,
            ],
            'kpis' => $kpis,
            'widgets' => $widgets,
            'charts' => [
                'orders_trend' => $metrics->ordersTrend($weekStart, $today),
                'sales_trend' => $metrics->salesTrend($weekStart, $today),
                'status_distribution' => $metrics->statusDistribution($monthStart),
            ],
            'tables' => $tables,
            'alerts' => $metrics->alerts(),
            'permissions' => [
                'orders_view' => $can['orders'],
                'tickets_view' => $can['tickets'],
                'shipping_view' => $can['shipping'],
                'inventory_view' => $can['inventory'],
                'expenses_view' => $can['expenses'],
                'audit_logs_view' => $can['audit'],
            ],
        ]);
    }
}
