<?php

namespace Database\Seeders;

use App\Models\ExpenseCategory;
use Illuminate\Database\Seeder;

class ExpenseCategoriesSeeder extends Seeder
{
    public function run(): void
    {
        // Defaults from 02_DATABASE_SCHEMA.md.
        $names = [
            'Ads', 'Shipping', 'Salaries', 'Commissions', 'Packaging',
            'Rent', 'Purchases', 'Operations', 'Other',
        ];

        foreach ($names as $name) {
            ExpenseCategory::firstOrCreate(
                ['name' => $name],
                ['status' => 'Active'],
            );
        }
    }
}
