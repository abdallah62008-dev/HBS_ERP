<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Phase 1 foundation seeders. Order matters:
     *   1. Permissions catalogue (independent).
     *   2. Roles (depend on permissions).
     *   3. Settings, lookups, fiscal year (independent).
     *   4. Admin user (depends on the super-admin role).
     */
    public function run(): void
    {
        $this->call([
            PermissionsSeeder::class,
            RolesSeeder::class,
            SettingsSeeder::class,
            ExpenseCategoriesSeeder::class,
            ReturnReasonsSeeder::class,
            MarketerPriceGroupsSeeder::class,
            MarketerPriceTiersSeeder::class,
            FiscalYearSeeder::class,
            WarehousesSeeder::class,
            ShippingCompaniesSeeder::class,
            LocationSeeder::class,
            // Finance Phase 2 — canonical payment methods. Independent of
            // cashboxes; default_cashbox_id is filled later by admins.
            PaymentMethodsSeeder::class,
            AdminUserSeeder::class,
        ]);
    }
}
