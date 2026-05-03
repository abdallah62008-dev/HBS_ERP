<?php

namespace Database\Seeders;

use App\Models\Warehouse;
use Illuminate\Database\Seeder;

class WarehousesSeeder extends Seeder
{
    public function run(): void
    {
        Warehouse::firstOrCreate(
            ['name' => 'Main Warehouse'],
            [
                'location' => 'Cairo, Egypt',
                'status' => 'Active',
                'is_default' => true,
            ],
        );
    }
}
