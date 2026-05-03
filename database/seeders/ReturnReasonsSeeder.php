<?php

namespace Database\Seeders;

use App\Models\ReturnReason;
use Illuminate\Database\Seeder;

class ReturnReasonsSeeder extends Seeder
{
    public function run(): void
    {
        // Defaults from 02_DATABASE_SCHEMA.md.
        $names = [
            'Customer Did Not Answer',
            'Refused Delivery',
            'Wrong Address',
            'Delayed Shipping',
            'Product Damaged',
            'Product Different',
            'Price Issue',
            'Changed Mind',
            'Shipping Company Issue',
        ];

        foreach ($names as $name) {
            ReturnReason::firstOrCreate(
                ['name' => $name],
                ['status' => 'Active'],
            );
        }
    }
}
