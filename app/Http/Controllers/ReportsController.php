<?php

namespace App\Http\Controllers;

use App\Services\ReportsService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * One controller, one method per report. Each method renders an Inertia
 * page with the report data. Date range comes from `from`/`to` query
 * params and defaults to the current month (handled in ReportsService).
 */
class ReportsController extends Controller
{
    public function __construct(
        private readonly ReportsService $reports,
    ) {}

    public function index(): Response
    {
        return Inertia::render('Reports/Index');
    }

    public function sales(Request $request): Response
    {
        return Inertia::render('Reports/Sales', $this->reports->sales($request->from, $request->to));
    }

    public function profit(Request $request): Response
    {
        return Inertia::render('Reports/Profit', $this->reports->profit($request->from, $request->to));
    }

    public function productProfitability(Request $request): Response
    {
        return Inertia::render('Reports/ProductProfitability', $this->reports->productProfitability($request->from, $request->to));
    }

    public function unprofitableProducts(Request $request): Response
    {
        return Inertia::render('Reports/UnprofitableProducts', $this->reports->unprofitableProducts($request->from, $request->to));
    }

    public function inventory(): Response
    {
        return Inertia::render('Reports/Inventory', $this->reports->inventory());
    }

    public function stockForecast(Request $request): Response
    {
        $lookback = (int) ($request->lookback ?? 30);
        return Inertia::render('Reports/StockForecast', $this->reports->stockForecast($lookback));
    }

    public function shipping(Request $request): Response
    {
        return Inertia::render('Reports/Shipping', $this->reports->shippingPerformance($request->from, $request->to));
    }

    public function collections(Request $request): Response
    {
        return Inertia::render('Reports/Collections', $this->reports->collections($request->from, $request->to));
    }

    public function returns(Request $request): Response
    {
        return Inertia::render('Reports/Returns', $this->reports->returns($request->from, $request->to));
    }

    public function marketers(Request $request): Response
    {
        return Inertia::render('Reports/Marketers', $this->reports->marketers($request->from, $request->to));
    }

    public function staff(Request $request): Response
    {
        return Inertia::render('Reports/Staff', $this->reports->staff($request->from, $request->to));
    }

    public function ads(Request $request): Response
    {
        return Inertia::render('Reports/Ads', $this->reports->ads($request->from, $request->to));
    }

    public function cashFlow(Request $request): Response
    {
        return Inertia::render('Reports/CashFlow', $this->reports->cashFlow($request->from, $request->to));
    }
}
