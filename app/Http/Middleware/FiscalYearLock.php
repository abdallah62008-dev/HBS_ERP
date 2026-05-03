<?php

namespace App\Http\Middleware;

use App\Models\Expense;
use App\Models\FiscalYear;
use App\Models\Order;
use App\Models\PurchaseInvoice;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 8 fiscal-year lock per 03_RBAC_SECURITY_AUDIT.md §"Fiscal Year Lock Rule".
 *
 * Applied to mutating routes that touch records belonging to a fiscal
 * year. If the target record's fiscal year is Closed, the request is
 * rejected unless the user holds `year_end.manage` (Super Admin override).
 *
 * Lookup:
 *   - Order      → check Order::fiscal_year_id directly.
 *   - Expense    → resolve fiscal year from expense_date (range match).
 *   - PurchaseInvoice → resolve fiscal year from invoice_date (range match).
 *
 * Apply via: Route::middleware('fiscal_year_lock')->put('/orders/{order}', …)
 */
class FiscalYearLock
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Super Admin bypass.
        if ($user && method_exists($user, 'hasPermission') && $user->hasPermission('year_end.manage')) {
            return $next($request);
        }

        // Order: explicit fiscal_year_id column.
        $order = $request->route('order');
        if ($order instanceof Order && $order->fiscal_year_id) {
            $year = FiscalYear::find($order->fiscal_year_id);
            $this->guard($year);
        }

        // Expense: resolve fiscal year by expense_date.
        $expense = $request->route('expense');
        if ($expense instanceof Expense && $expense->expense_date) {
            $this->guard($this->yearForDate($expense->expense_date));
        }

        // Purchase Invoice: resolve fiscal year by invoice_date.
        $purchaseInvoice = $request->route('purchaseInvoice');
        if ($purchaseInvoice instanceof PurchaseInvoice && $purchaseInvoice->invoice_date) {
            $this->guard($this->yearForDate($purchaseInvoice->invoice_date));
        }

        return $next($request);
    }

    private function yearForDate($date): ?FiscalYear
    {
        return FiscalYear::query()
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->first();
    }

    private function guard(?FiscalYear $year): void
    {
        if ($year && $year->isClosed()) {
            abort(403, "Fiscal year {$year->name} is closed. Edits require an approval-override.");
        }
    }
}
