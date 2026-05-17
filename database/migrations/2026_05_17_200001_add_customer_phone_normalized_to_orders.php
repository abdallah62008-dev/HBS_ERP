<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Orders & Products O-2 — snapshot the customer's normalized phone
 * onto each order at create time.
 *
 * Why a snapshot instead of joining to `customers.normalized_phone`:
 *
 *   1. Customers can change their primary phone after an order is
 *      placed; reports / WhatsApp messages for the historical order
 *      should still resolve the contact that existed at order time.
 *   2. The dedupe service queries orders directly without a customer
 *      join — keeping the column on `orders` keeps the query cheap.
 *
 * Existing legacy snapshot columns (`customer_phone`,
 * `customer_phone_secondary`) stay unchanged. The new column is an
 * E.164 sibling that the service writes alongside.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'customer_phone_normalized')) {
                $table->string('customer_phone_normalized', 20)
                    ->nullable()
                    ->after('customer_phone_whatsapp');
            }
        });

        // Index added separately, idempotent on re-run.
        try {
            Schema::table('orders', function (Blueprint $table) {
                $table->index('customer_phone_normalized', 'orders_customer_phone_normalized_index');
            });
        } catch (\Throwable $e) {
            // Already exists — ignore.
        }
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            try {
                $table->dropIndex('orders_customer_phone_normalized_index');
            } catch (\Throwable $e) {
                // No such index — ignore.
            }
            if (Schema::hasColumn('orders', 'customer_phone_normalized')) {
                $table->dropColumn('customer_phone_normalized');
            }
        });
    }
};
