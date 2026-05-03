<?php

namespace Database\Seeders;

use App\Models\ShippingCompany;
use Illuminate\Database\Seeder;

class ShippingCompaniesSeeder extends Seeder
{
    public function run(): void
    {
        ShippingCompany::firstOrCreate(
            ['name' => 'Internal Courier'],
            [
                'contact_name' => 'Operations',
                'phone' => null,
                'api_enabled' => false,
                'status' => 'Active',
            ],
        );
    }
}
