<?php

namespace App\Exports;

use App\Services\ReportsService;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class InventoryExport implements FromArray, WithHeadings, WithStyles, ShouldAutoSize
{
    public function __construct(private readonly ReportsService $reports) {}

    public function array(): array
    {
        $rows = $this->reports->inventory()['rows'];
        return array_map(fn ($r) => [
            $r->sku, $r->name, $r->reorder_level,
            $r->on_hand, $r->reserved, $r->available,
            $r->is_low ? 'LOW' : 'OK',
        ], $rows);
    }

    public function headings(): array
    {
        return ['SKU', 'Product', 'Reorder ≤', 'On hand', 'Reserved', 'Available', 'Status'];
    }

    public function styles(Worksheet $sheet): array
    {
        return [1 => ['font' => ['bold' => true]]];
    }
}
