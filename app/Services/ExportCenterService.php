<?php

namespace App\Services;

use App\Exports\CustomersExport;
use App\Exports\ExpensesExport;
use App\Exports\InventoryExport;
use App\Exports\OrdersExport;
use App\Exports\ProductsExport;
use App\Models\ExportLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Maatwebsite\Excel\Facades\Excel;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Single entry point for the Export Center. Picks the right Exporter
 * based on `type`, applies filters, streams the .xlsx, and writes an
 * `export_logs` row.
 *
 * The orders + marketer-statement exports already exist; we wrap them
 * here so the audit trail and history page see every export uniformly.
 */
class ExportCenterService
{
    public function __construct(
        private readonly ReportsService $reports,
    ) {}

    /**
     * @param  array<string,mixed>  $filters
     */
    public function download(string $type, array $filters, Request $request): BinaryFileResponse
    {
        [$exporter, $rowsCount] = $this->buildExporter($type, $filters);

        $filename = sprintf('%s-%s.xlsx', $type, now()->format('Y-m-d_His'));

        ExportLog::create([
            'export_type' => $type,
            'filters_json' => $filters,
            'file_url' => null, // streamed; not persisted to disk
            'rows_count' => $rowsCount,
            'exported_by' => Auth::id(),
            'ip_address' => $request->ip(),
            'created_at' => now(),
        ]);

        AuditLogService::log('export', 'imports-exports', null, null, newValues: [
            'type' => $type,
            'filters' => $filters,
            'rows_count' => $rowsCount,
        ]);

        return Excel::download($exporter, $filename);
    }

    /**
     * Maps slug → (exporter instance, row count for the log).
     *
     * @param  array<string,mixed>  $filters
     * @return array{0:object, 1:int}
     */
    private function buildExporter(string $type, array $filters): array
    {
        return match ($type) {
            'orders' => [
                new OrdersExport($filters, includeProfit: Auth::user()?->hasPermission('orders.view_profit') ?? false),
                \App\Models\Order::query()
                    ->forCurrentMarketer()
                    ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
                    ->count(),
            ],
            'products' => [
                new ProductsExport($filters),
                \App\Models\Product::query()
                    ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
                    ->count(),
            ],
            'customers' => [
                new CustomersExport($filters),
                \App\Models\Customer::query()
                    ->when($filters['risk_level'] ?? null, fn ($q, $v) => $q->where('risk_level', $v))
                    ->count(),
            ],
            'expenses' => [
                new ExpensesExport($filters),
                \App\Models\Expense::query()
                    ->when($filters['from'] ?? null, fn ($q, $v) => $q->whereDate('expense_date', '>=', $v))
                    ->when($filters['to'] ?? null, fn ($q, $v) => $q->whereDate('expense_date', '<=', $v))
                    ->count(),
            ],
            'inventory' => [
                new InventoryExport($this->reports),
                count($this->reports->inventory()['rows']),
            ],
            default => throw new RuntimeException("Unknown export type: {$type}"),
        };
    }

    /** @return array<int, array{slug:string, label:string, permission:?string}> */
    public function describeAll(): array
    {
        return [
            ['slug' => 'orders', 'label' => 'Orders', 'permission' => 'orders.export'],
            ['slug' => 'products', 'label' => 'Products', 'permission' => 'products.export'],
            ['slug' => 'customers', 'label' => 'Customers', 'permission' => 'customers.view'],
            ['slug' => 'expenses', 'label' => 'Expenses', 'permission' => 'expenses.export'],
            ['slug' => 'inventory', 'label' => 'Inventory snapshot', 'permission' => 'inventory.view'],
        ];
    }
}
