<?php

namespace App\Services\Importers;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Services\SettingsService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class ExpenseImporter extends AbstractImporter
{
    public function label(): string { return 'Expenses'; }
    public function slug(): string { return 'expenses'; }

    public function headers(): array
    {
        return ['title', 'category', 'amount', 'currency_code',
            'expense_date', 'payment_method', 'notes'];
    }

    public function headerNotes(): array
    {
        return [
            'category' => 'Required. Matched by name; created if missing.',
            'amount' => 'Numeric. Must be > 0.',
            'expense_date' => 'YYYY-MM-DD.',
        ];
    }

    public function validateRow(array $row): ?string
    {
        if (! $this->pick($row, 'title')) return 'Title is required.';
        if (! $this->pick($row, 'category')) return 'Category is required.';
        $amount = $this->pickFloat($row, 'amount');
        if ($amount <= 0) return 'Amount must be > 0.';
        if (! $this->pick($row, 'expense_date')) return 'Expense date is required.';
        try {
            Carbon::parse($this->pick($row, 'expense_date'));
        } catch (\Throwable) {
            return 'Invalid expense_date — use YYYY-MM-DD.';
        }
        return null;
    }

    public function persistRow(array $row): Model
    {
        $userId = Auth::id();
        $categoryName = $this->pick($row, 'category');
        $category = ExpenseCategory::firstOrCreate(
            ['name' => $categoryName],
            ['status' => 'Active'],
        );

        return Expense::create([
            'title' => $this->pick($row, 'title'),
            'expense_category_id' => $category->id,
            'amount' => $this->pickFloat($row, 'amount'),
            'currency_code' => $this->pick($row, 'currency_code') ?: SettingsService::get('currency_code', 'EGP'),
            'expense_date' => Carbon::parse($this->pick($row, 'expense_date'))->toDateString(),
            'payment_method' => $this->pick($row, 'payment_method'),
            'notes' => $this->pick($row, 'notes'),
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);
    }
}
