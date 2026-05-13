<?php

namespace Database\Seeders;

use App\Models\PaymentMethod;
use Illuminate\Database\Seeder;

/**
 * Finance Phase 2 — seeds the seven canonical payment methods.
 *
 * Idempotent: matches by `code` so re-running adds nothing on a system
 * that already has them. `default_cashbox_id` is intentionally NOT set
 * here because cashboxes vary per installation — admins assign defaults
 * from the UI after creating their own cashboxes.
 */
class PaymentMethodsSeeder extends Seeder
{
    public function run(): void
    {
        $methods = [
            ['code' => 'cash',          'name' => 'Cash',          'type' => 'cash'],
            ['code' => 'visa_pos',      'name' => 'Visa / POS',    'type' => 'card'],
            ['code' => 'vodafone_cash', 'name' => 'Vodafone Cash', 'type' => 'digital_wallet'],
            ['code' => 'bank_transfer', 'name' => 'Bank Transfer', 'type' => 'bank_transfer'],
            ['code' => 'courier_cod',   'name' => 'Courier COD',   'type' => 'courier_cod'],
            ['code' => 'amazon_wallet', 'name' => 'Amazon Wallet', 'type' => 'marketplace'],
            ['code' => 'noon_wallet',   'name' => 'Noon Wallet',   'type' => 'marketplace'],
        ];

        foreach ($methods as $m) {
            PaymentMethod::updateOrCreate(
                ['code' => $m['code']],
                [
                    'name' => $m['name'],
                    'type' => $m['type'],
                    'is_active' => true,
                ],
            );
        }
    }
}
