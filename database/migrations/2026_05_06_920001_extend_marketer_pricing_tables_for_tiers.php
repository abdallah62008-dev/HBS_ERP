<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5.6 — Marketer pricing tiers.
 *
 * The brief asked for a `marketer_price_tiers` lookup table plus a
 * `product_marketer_tier_prices` join. The existing schema already has
 * close-fitting tables:
 *
 *   - marketer_price_groups (id, name, status, ...)
 *   - marketer_product_prices (id, marketer_price_group_id, product_id,
 *       product_variant_id, trade_price, minimum_selling_price, ...)
 *
 * Per the brief's preference ("If existing tables are close but not enough,
 * extend minimally") we extend in place rather than create parallel tables:
 *
 *   - marketer_price_groups gains `code` (varchar 8 nullable, indexed) and
 *     `sort_order` (smallint nullable). The Phase 5.6 tiers (A/B/D/E) are
 *     seeded by code; legacy rows (Bronze/Silver/Gold/VIP) keep their NULL
 *     codes so they continue to work for marketers already mapped to them.
 *   - marketer_product_prices gains `shipping_cost` (decimal 12,2 nullable)
 *     and `vat_percent` (decimal 5,2 nullable). The product-tier table the
 *     brief sketched maps onto a row of marketer_product_prices keyed on
 *     (marketer_price_group_id, product_id) with marketer_cost_price stored
 *     in the existing `trade_price` column.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('marketer_price_groups', function (Blueprint $table) {
            if (! Schema::hasColumn('marketer_price_groups', 'code')) {
                $table->string('code', 8)->nullable()->after('name');
                $table->index('code', 'marketer_price_groups_code_index');
            }
            if (! Schema::hasColumn('marketer_price_groups', 'sort_order')) {
                $table->unsignedSmallInteger('sort_order')->nullable()->after('code');
            }
        });

        Schema::table('marketer_product_prices', function (Blueprint $table) {
            if (! Schema::hasColumn('marketer_product_prices', 'shipping_cost')) {
                $table->decimal('shipping_cost', 12, 2)->nullable()->after('minimum_selling_price');
            }
            if (! Schema::hasColumn('marketer_product_prices', 'vat_percent')) {
                $table->decimal('vat_percent', 5, 2)->nullable()->after('shipping_cost');
            }
        });
    }

    public function down(): void
    {
        Schema::table('marketer_product_prices', function (Blueprint $table) {
            if (Schema::hasColumn('marketer_product_prices', 'vat_percent')) {
                $table->dropColumn('vat_percent');
            }
            if (Schema::hasColumn('marketer_product_prices', 'shipping_cost')) {
                $table->dropColumn('shipping_cost');
            }
        });

        Schema::table('marketer_price_groups', function (Blueprint $table) {
            if (Schema::hasColumn('marketer_price_groups', 'sort_order')) {
                $table->dropColumn('sort_order');
            }
            if (Schema::hasColumn('marketer_price_groups', 'code')) {
                $table->dropIndex('marketer_price_groups_code_index');
                $table->dropColumn('code');
            }
        });
    }
};
