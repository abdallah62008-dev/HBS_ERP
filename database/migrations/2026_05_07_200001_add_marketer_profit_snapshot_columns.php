<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5.9 — Marketer profit snapshot columns.
 *
 * Wires the Phase 5.6/5.7 marketer pricing tier data into the actual
 * profit chain. Adds:
 *
 *   - orders.marketer_profit         decimal(12,2) NULL
 *     Per-order calculated marketer profit using
 *       SUM ( unit_price - unit_price*vat% - cost - shipping ) × qty
 *     across the order's items, resolved through the
 *     marketer-specific → tier → product-default chain.
 *     NULL when the order has no marketer_id.
 *
 *   - order_items.marketer_shipping_cost decimal(12,2) NULL
 *   - order_items.marketer_vat_percent   decimal(5,2)  NULL
 *     Per-line snapshot of the resolved tier shipping/VAT, so the
 *     profit can be audited or recomputed without re-running the
 *     resolution chain (which can drift if tier rows are edited).
 *     order_items.marketer_trade_price was added in Phase 2 and is
 *     reused as the cost-price snapshot.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'marketer_profit')) {
                $table->decimal('marketer_profit', 12, 2)->nullable()->after('net_profit');
            }
        });

        Schema::table('order_items', function (Blueprint $table) {
            if (! Schema::hasColumn('order_items', 'marketer_shipping_cost')) {
                $table->decimal('marketer_shipping_cost', 12, 2)->nullable()->after('marketer_trade_price');
            }
            if (! Schema::hasColumn('order_items', 'marketer_vat_percent')) {
                $table->decimal('marketer_vat_percent', 5, 2)->nullable()->after('marketer_shipping_cost');
            }
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            if (Schema::hasColumn('order_items', 'marketer_vat_percent')) {
                $table->dropColumn('marketer_vat_percent');
            }
            if (Schema::hasColumn('order_items', 'marketer_shipping_cost')) {
                $table->dropColumn('marketer_shipping_cost');
            }
        });

        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'marketer_profit')) {
                $table->dropColumn('marketer_profit');
            }
        });
    }
};
