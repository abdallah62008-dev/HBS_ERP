<?php

namespace Database\Seeders;

use App\Services\SettingsService;
use Illuminate\Database\Seeder;

/**
 * Seeds defaults from 02_DATABASE_SCHEMA.md and 04_BUSINESS_WORKFLOWS.md.
 * Goes through SettingsService so the cache is invalidated correctly.
 *
 * Idempotent: re-running keeps existing values (only inserts when key
 * doesn't yet exist). To reset everything, truncate the table and run
 * the seeder again.
 */
class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        $existing = array_keys(SettingsService::all());

        foreach ($this->defaults() as $key => [$group, $valueType, $value]) {
            if (in_array($key, $existing, true)) {
                continue;
            }

            SettingsService::set($key, $value, $group, $valueType);
        }
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: mixed}>
     *   Format: key => [group, value_type, value]
     */
    private function defaults(): array
    {
        return [
            // Localisation
            'country' => ['general', 'string', 'Egypt'],
            'default_country_code' => ['localization', 'string', 'EG'],
            'currency_code' => ['general', 'string', 'EGP'],
            'currency_symbol' => ['general', 'string', 'جنيه'],
            'timezone' => ['general', 'string', 'Africa/Cairo'],

            // Tax
            'tax_enabled' => ['tax', 'boolean', true],
            'default_tax_rate' => ['tax', 'number', 14],
            'tax_mode' => ['tax', 'string', 'excluded'],

            // Orders
            'order_prefix' => ['orders', 'string', 'ORD'],

            // Fiscal year
            'fiscal_year_enabled' => ['fiscal_year', 'boolean', true],

            // Shipping
            'label_size' => ['shipping', 'string', '4x6'],
            'shipping_photo_required' => ['shipping', 'boolean', true],

            // Profit guard
            'profit_guard_enabled' => ['profit', 'boolean', true],
            'minimum_profit_required' => ['profit', 'number', 0],

            // Marketers
            'marketer_profit_after_delivery_only' => ['marketers', 'boolean', true],

            // Inventory
            'allow_negative_stock' => ['inventory', 'boolean', false],
        ];
    }
}
