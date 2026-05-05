<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5.8 — Order address simplification + WhatsApp/secondary phone snapshot.
 *
 * The Order Create form previously had a separate "Shipping address" block
 * that duplicated location fields already captured on the customer. From
 * this phase, the customer's main address IS the shipping address. To keep
 * per-order contact context (which the customer's profile alone can't
 * preserve when phones change later), we snapshot two extra fields onto
 * `orders`:
 *   - customer_phone_secondary: optional second phone for THIS order
 *   - customer_phone_whatsapp:  is the primary phone reachable via WhatsApp?
 *
 * `customers.primary_phone_whatsapp` carries the same flag at the customer
 * level so it can default sensibly on future orders for the same customer.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'customer_phone_secondary')) {
                $table->string('customer_phone_secondary', 32)->nullable()->after('customer_phone');
            }
            if (! Schema::hasColumn('orders', 'customer_phone_whatsapp')) {
                $table->boolean('customer_phone_whatsapp')->default(true)->after('customer_phone_secondary');
            }
        });

        Schema::table('customers', function (Blueprint $table) {
            if (! Schema::hasColumn('customers', 'primary_phone_whatsapp')) {
                $table->boolean('primary_phone_whatsapp')->default(true)->after('secondary_phone');
            }
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            if (Schema::hasColumn('customers', 'primary_phone_whatsapp')) {
                $table->dropColumn('primary_phone_whatsapp');
            }
        });

        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'customer_phone_whatsapp')) {
                $table->dropColumn('customer_phone_whatsapp');
            }
            if (Schema::hasColumn('orders', 'customer_phone_secondary')) {
                $table->dropColumn('customer_phone_secondary');
            }
        });
    }
};
