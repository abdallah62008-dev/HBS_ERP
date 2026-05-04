<?php

namespace Database\Seeders;

use App\Models\MarketerPriceGroup;
use Illuminate\Database\Seeder;

/**
 * Phase 5.6 — Seed the four marketer pricing tiers (A, B, D, E).
 *
 * Idempotent: keyed on `code` so re-running updates labels but never
 * inserts duplicates. Pre-existing rows (Bronze/Silver/Gold/VIP from the
 * earlier MarketerPriceGroupsSeeder) keep their NULL `code` and continue
 * to function for any marketer already mapped to them.
 */
class MarketerPriceTiersSeeder extends Seeder
{
    public function run(): void
    {
        $tiers = [
            ['code' => 'A', 'name' => 'Marketer A', 'sort_order' => 10],
            ['code' => 'B', 'name' => 'Marketer B', 'sort_order' => 20],
            ['code' => 'D', 'name' => 'Marketer D', 'sort_order' => 30],
            ['code' => 'E', 'name' => 'Marketer E', 'sort_order' => 40],
        ];

        foreach ($tiers as $tier) {
            MarketerPriceGroup::updateOrCreate(
                ['code' => $tier['code']],
                [
                    'name' => $tier['name'],
                    'sort_order' => $tier['sort_order'],
                    'status' => 'Active',
                ],
            );
        }
    }
}
