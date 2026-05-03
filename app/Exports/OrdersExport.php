<?php

namespace App\Exports;

use App\Models\Order;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Streams orders to an .xlsx file. `FromQuery` lets PhpSpreadsheet read
 * in chunks instead of loading everything into memory — important once
 * the table grows past a few thousand rows.
 *
 * Filters mirror the Orders index page so an exported sheet matches what
 * the operator was looking at when they clicked Export.
 */
class OrdersExport implements FromQuery, WithHeadings, WithMapping, WithStyles, ShouldAutoSize
{
    /**
     * @param  array{q?:?string, status?:?string, risk_level?:?string, shipping_status?:?string}  $filters
     * @param  bool  $includeProfit  Whether the user has the orders.view_profit permission
     */
    public function __construct(
        private readonly array $filters = [],
        private readonly bool $includeProfit = false,
    ) {}

    public function query(): EloquentBuilder|Builder
    {
        return Order::query()
            ->forCurrentMarketer()
            ->when($this->filters['q'] ?? null, function ($q, $term) {
                $q->where(function ($w) use ($term) {
                    $w->where('order_number', 'like', "%{$term}%")
                        ->orWhere('customer_name', 'like', "%{$term}%")
                        ->orWhere('customer_phone', 'like', "%{$term}%");
                });
            })
            ->when($this->filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($this->filters['risk_level'] ?? null, fn ($q, $v) => $q->where('customer_risk_level', $v))
            ->when($this->filters['shipping_status'] ?? null, fn ($q, $v) => $q->where('shipping_status', $v))
            ->orderByDesc('id');
    }

    public function headings(): array
    {
        $base = [
            'Order #', 'Created', 'Status', 'Shipping status', 'Collection status',
            'Customer', 'Phone', 'City', 'Governorate', 'Country',
            'Subtotal', 'Discount', 'Shipping', 'Tax', 'Extra fees', 'Total', 'Currency',
            'Risk level', 'Risk score', 'Duplicate score',
            'Source', 'Notes',
        ];

        if ($this->includeProfit) {
            $base[] = 'Product cost';
            $base[] = 'Gross profit';
            $base[] = 'Net profit';
        }

        return $base;
    }

    public function map($order): array
    {
        $row = [
            $order->order_number,
            $order->created_at?->toDateTimeString(),
            $order->status,
            $order->shipping_status,
            $order->collection_status,
            $order->customer_name,
            $order->customer_phone,
            $order->city,
            $order->governorate,
            $order->country,
            $order->subtotal,
            $order->discount_amount,
            $order->shipping_amount,
            $order->tax_amount,
            $order->extra_fees,
            $order->total_amount,
            $order->currency_code,
            $order->customer_risk_level,
            $order->customer_risk_score,
            $order->duplicate_score,
            $order->source,
            $order->notes,
        ];

        if ($this->includeProfit) {
            $row[] = $order->product_cost_total;
            $row[] = $order->gross_profit;
            $row[] = $order->net_profit;
        }

        return $row;
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            // Bold headings
            1 => ['font' => ['bold' => true]],
        ];
    }
}
