<?php

namespace App\Services\Importers;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class CustomerImporter extends AbstractImporter
{
    public function label(): string { return 'Customers'; }
    public function slug(): string { return 'customers'; }

    public function headers(): array
    {
        return ['name', 'primary_phone', 'secondary_phone', 'email',
            'city', 'governorate', 'country', 'default_address',
            'customer_type', 'notes'];
    }

    public function headerNotes(): array
    {
        return [
            'name' => 'Required.',
            'primary_phone' => 'Required. Used to detect duplicates.',
            'customer_type' => 'Normal / VIP / Watchlist / Blacklist. Defaults to Normal.',
        ];
    }

    public function validateRow(array $row): ?string
    {
        if (! $this->pick($row, 'name')) return 'Name is required.';
        if (! $this->pick($row, 'primary_phone')) return 'Primary phone is required.';
        if (! $this->pick($row, 'city')) return 'City is required.';
        if (! $this->pick($row, 'country')) return 'Country is required.';
        if (! $this->pick($row, 'default_address')) return 'Default address is required.';

        $type = $this->pick($row, 'customer_type');
        if ($type && ! in_array($type, ['Normal', 'VIP', 'Watchlist', 'Blacklist'], true)) {
            return "Invalid customer_type: {$type}.";
        }
        return null;
    }

    public function findDuplicate(array $row): ?Model
    {
        $phone = preg_replace('/[\s\-+]/', '', $this->pick($row, 'primary_phone') ?? '');
        if (! $phone) return null;
        return Customer::query()
            ->whereRaw("REPLACE(REPLACE(REPLACE(primary_phone, ' ', ''), '-', ''), '+', '') = ?", [$phone])
            ->first();
    }

    public function persistRow(array $row): Model
    {
        $userId = Auth::id();
        return Customer::create([
            'name' => $this->pick($row, 'name'),
            'primary_phone' => $this->pick($row, 'primary_phone'),
            'secondary_phone' => $this->pick($row, 'secondary_phone'),
            'email' => $this->pick($row, 'email'),
            'city' => $this->pick($row, 'city'),
            'governorate' => $this->pick($row, 'governorate'),
            'country' => $this->pick($row, 'country'),
            'default_address' => $this->pick($row, 'default_address'),
            'customer_type' => $this->pick($row, 'customer_type') ?: 'Normal',
            'notes' => $this->pick($row, 'notes'),
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);
    }
}
