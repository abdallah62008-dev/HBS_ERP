<?php

namespace App\Http\Controllers;

use App\Exports\OrdersExport;
use App\Models\Order;
use App\Services\AuditLogService;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Streams an .xlsx of the current orders list. The user must hold
 * `orders.export` (route middleware) and the export respects:
 *   - the marketer ownership scope (Order::scopeForCurrentMarketer)
 *   - the orders.view_profit permission gate (profit columns are
 *     omitted when the user doesn't hold it)
 *
 * Every export writes an audit_logs entry per 03_RBAC_SECURITY_AUDIT.md
 * §3 — exported data is sensitive.
 */
class OrdersExportController extends Controller
{
    public function __invoke(Request $request): BinaryFileResponse
    {
        $filters = $request->only(['q', 'status', 'risk_level', 'shipping_status']);
        $includeProfit = $request->user()?->hasPermission('orders.view_profit') ?? false;

        AuditLogService::log(
            action: 'export',
            module: 'orders',
            recordType: Order::class,
            newValues: [
                'filters' => $filters,
                'include_profit' => $includeProfit,
            ],
        );

        $filename = 'orders-' . now()->format('Y-m-d_His') . '.xlsx';

        return Excel::download(new OrdersExport($filters, $includeProfit), $filename);
    }
}
