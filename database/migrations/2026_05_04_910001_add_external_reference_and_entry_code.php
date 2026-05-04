<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5.4 — Order external reference + entry code (additive).
 *
 *   - orders.external_order_reference (nullable, indexed) — operator-supplied
 *     website/marketplace order id (e.g. "WEB-10295", "AMZ-987654"). NOT unique:
 *     the same value may legitimately come from different sources or imports.
 *   - orders.entry_code (nullable, indexed) — frozen at create time. Sourced
 *     from the marketer.code if the order has a marketer_id, otherwise from
 *     the creator's users.entry_code, otherwise initials of the creator's
 *     name. Used together with order_number to render display_order_number.
 *   - users.entry_code (nullable, indexed) — optional staff identifier used
 *     when the order is entered by a non-marketer user.
 *
 * Backwards-compatible: nullable, indexed only. No data migration needed.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'external_order_reference')) {
                $table->string('external_order_reference', 64)->nullable()->after('source');
                $table->index('external_order_reference', 'orders_external_order_reference_index');
            }
            if (! Schema::hasColumn('orders', 'entry_code')) {
                $table->string('entry_code', 16)->nullable()->after('external_order_reference');
                $table->index('entry_code', 'orders_entry_code_index');
            }
        });

        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'entry_code')) {
                $table->string('entry_code', 16)->nullable()->after('phone');
                $table->index('entry_code', 'users_entry_code_index');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'entry_code')) {
                $table->dropIndex('orders_entry_code_index');
                $table->dropColumn('entry_code');
            }
            if (Schema::hasColumn('orders', 'external_order_reference')) {
                $table->dropIndex('orders_external_order_reference_index');
                $table->dropColumn('external_order_reference');
            }
        });

        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'entry_code')) {
                $table->dropIndex('users_entry_code_index');
                $table->dropColumn('entry_code');
            }
        });
    }
};
