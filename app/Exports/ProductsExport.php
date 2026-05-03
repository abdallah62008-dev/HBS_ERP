<?php

namespace App\Exports;

use App\Models\Product;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ProductsExport implements FromQuery, WithHeadings, WithMapping, WithStyles, ShouldAutoSize
{
    /** @param array<string,mixed> $filters */
    public function __construct(private readonly array $filters = []) {}

    public function query(): EloquentBuilder|Builder
    {
        return Product::query()
            ->with('category:id,name')
            ->when($this->filters['q'] ?? null, function ($q, $term) {
                $q->where(function ($w) use ($term) {
                    $w->where('name', 'like', "%{$term}%")
                        ->orWhere('sku', 'like', "%{$term}%");
                });
            })
            ->when($this->filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->orderBy('name');
    }

    public function headings(): array
    {
        return ['SKU', 'Name', 'Category', 'Cost', 'Selling', 'Trade', 'Min selling',
            'Tax rate', 'Reorder level', 'Status'];
    }

    public function map($p): array
    {
        return [
            $p->sku, $p->name, $p->category?->name ?? '',
            $p->cost_price, $p->selling_price, $p->marketer_trade_price, $p->minimum_selling_price,
            $p->tax_rate, $p->reorder_level, $p->status,
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [1 => ['font' => ['bold' => true]]];
    }
}
