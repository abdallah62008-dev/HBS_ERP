<?php

namespace Database\Seeders;

use App\Models\MarketerPriceGroup;
use Illuminate\Database\Seeder;

class MarketerPriceGroupsSeeder extends Seeder
{
    public function run(): void
    {
        // Defaults from 02_DATABASE_SCHEMA.md.
        $names = ['Bronze', 'Silver', 'Gold', 'VIP'];

        foreach ($names as $name) {
            MarketerPriceGroup::firstOrCreate(
                ['name' => $name],
                ['status' => 'Active'],
            );
        }
    }
}
