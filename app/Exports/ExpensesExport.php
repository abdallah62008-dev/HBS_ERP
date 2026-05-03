<?php

namespace App\Exports;

use App\Models\Expense;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ExpensesExport implements FromQuery, WithHeadings, WithMapping, WithStyles, ShouldAutoSize
{
    /** @param array<string,mixed> $filters */
    public function __construct(private readonly array $filters = []) {}

    public function query(): EloquentBuilder|Builder
    {
        return Expense::query()
            ->with(['category:id,name', 'createdBy:id,name'])
            ->when($this->filters['from'] ?? null, fn ($q, $v) => $q->whereDate('expense_date', '>=', $v))
            ->when($this->filters['to'] ?? null, fn ($q, $v) => $q->whereDate('expense_date', '<=', $v))
            ->when($this->filters['category_id'] ?? null, fn ($q, $v) => $q->where('expense_category_id', $v))
            ->orderBy('expense_date', 'desc');
    }

    public function headings(): array
    {
        return ['Date', 'Title', 'Category', 'Amount', 'Currency',
            'Payment method', 'Notes', 'Created by'];
    }

    public function map($e): array
    {
        return [
            $e->expense_date?->toDateString(),
            $e->title, $e->category?->name,
            $e->amount, $e->currency_code,
            $e->payment_method, $e->notes,
            $e->createdBy?->name,
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [1 => ['font' => ['bold' => true]]];
    }
}
