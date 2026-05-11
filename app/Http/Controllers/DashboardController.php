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
 * Shows real KPIs, mini charts, and short attention tables for
 * super-admin / staff users. Marketer-role users are redirected to
 * the simplified /marketer/dashboard so they never see admin data.
 *
 * All metric calculation lives in DashboardMetricsService — this
 * controller only handles routing, permission gating, and prop
 * shaping for the Inertia view.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request, DashboardMetricsService $metrics): Response|RedirectResponse
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

        // latest_orders carries customer names, totals, and statuses — it
        // must be gated server-side by `orders.view`. Frontend hiding
        // alone would leak through the Inertia props payload.
        $canViewOrders = (bool) $user?->hasPermission('orders.view');
        $canViewTickets = (bool) $user?->hasPermission('tickets.view');

        $tables = [
            'latest_orders' => $canViewOrders ? $metrics->latestOrders() : [],
            'low_stock' => $metrics->lowStockProducts(),
            'delayed_shipments' => $metrics->delayedShipments(),
        ];

        $payload = [
            'kpis' => $metrics->kpis($today, $yesterday, $monthStart),
            'charts' => [
                'orders_trend' => $metrics->ordersTrend($weekStart, $today),
                'sales_trend' => $metrics->salesTrend($weekStart, $today),
                'status_distribution' => $metrics->statusDistribution($monthStart),
            ],
            'tables' => $tables,
            'alerts' => $metrics->alerts(),
            'permissions' => [
                'orders_view' => $canViewOrders,
                'tickets_view' => $canViewTickets,
            ],
        ];

        // Open-tickets KPI is only sent when the user is permitted to
        // see tickets. Frontend keys off prop presence (null → hidden).
        if ($canViewTickets) {
            $payload['kpis']['open_tickets'] = $metrics->openTicketsCount();
        }

        return Inertia::render('Dashboard', $payload);
    }
}
