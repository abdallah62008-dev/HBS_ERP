<?php

namespace App\Exports;

use App\Models\Customer;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class CustomersExport implements FromQuery, WithHeadings, WithMapping, WithStyles, ShouldAutoSize
{
    /** @param array<string,mixed> $filters */
    public function __construct(private readonly array $filters = []) {}

    public function query(): EloquentBuilder|Builder
    {
        return Customer::query()
            ->when($this->filters['q'] ?? null, function ($q, $term) {
                $q->where(function ($w) use ($term) {
                    $w->where('name', 'like', "%{$term}%")
                        ->orWhere('primary_phone', 'like', "%{$term}%");
                });
            })
            ->when($this->filters['risk_level'] ?? null, fn ($q, $v) => $q->where('risk_level', $v))
            ->orderBy('name');
    }

    public function headings(): array
    {
        return ['Name', 'Primary phone', 'Secondary phone', 'Email', 'City', 'Governorate',
            'Country', 'Default address', 'Customer type', 'Risk score', 'Risk level'];
    }

    public function map($c): array
    {
        return [
            $c->name, $c->primary_phone, $c->secondary_phone, $c->email,
            $c->city, $c->governorate, $c->country, $c->default_address,
            $c->customer_type, $c->risk_score, $c->risk_level,
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [1 => ['font' => ['bold' => true]]];
    }
}
