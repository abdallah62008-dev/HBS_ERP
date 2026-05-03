<?php

namespace App\Exports;

use App\Models\Marketer;
use App\Models\MarketerTransaction;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Marketer transaction history → .xlsx. Used by both the admin
 * Statement export and the marketer-portal Statement export.
 */
class MarketerStatementExport implements FromQuery, WithHeadings, WithMapping, WithStyles, ShouldAutoSize
{
    public function __construct(
        private readonly Marketer $marketer,
        private readonly ?string $from = null,
        private readonly ?string $to = null,
    ) {}

    public function query(): EloquentBuilder|Builder
    {
        return MarketerTransaction::query()
            ->where('marketer_id', $this->marketer->id)
            ->when($this->from, fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($this->to, fn ($q, $v) => $q->whereDate('created_at', '<=', $v))
            ->with('order:id,order_number,status')
            ->orderBy('created_at');
    }

    public function headings(): array
    {
        return [
            'Date', 'Order #', 'Order status', 'Type', 'Status',
            'Selling', 'Trade', 'Shipping', 'Tax', 'Extra fees',
            'Net profit', 'Notes',
        ];
    }

    public function map($tx): array
    {
        return [
            $tx->created_at?->toDateTimeString(),
            $tx->order?->order_number ?? '—',
            $tx->order?->status ?? '—',
            $tx->transaction_type,
            $tx->status,
            $tx->selling_price,
            $tx->trade_product_price,
            $tx->shipping_amount,
            $tx->tax_amount,
            $tx->extra_fees,
            $tx->net_profit,
            $tx->notes,
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}
