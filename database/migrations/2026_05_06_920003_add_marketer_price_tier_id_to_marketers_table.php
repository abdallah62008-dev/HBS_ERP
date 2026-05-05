<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5.7 — Marketer pricing tier selection.
 *
 * Adds a separate FK to `marketer_price_groups` that points specifically at
 * the tier rows (code A/B/D/E from Phase 5.6). The existing `price_group_id`
 * column stays — it still drives the legacy per-(group, product) pricing
 * stored in `marketer_product_prices`. The two are kept distinct so future
 * profit-resolution logic can layer "marketer-specific price → tier price →
 * product default" without conflating concepts.
 *
 * Nullable: existing marketers don't get a backfill; new marketers default
 * to code "A" via the controller, not via DB default.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('marketers', function (Blueprint $table) {
            if (! Schema::hasColumn('marketers', 'marketer_price_tier_id')) {
                $table->foreignId('marketer_price_tier_id')
                    ->nullable()
                    ->after('price_group_id')
                    ->constrained('marketer_price_groups')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('marketers', function (Blueprint $table) {
            if (Schema::hasColumn('marketers', 'marketer_price_tier_id')) {
                $table->dropConstrainedForeignId('marketer_price_tier_id');
            }
        });
    }
};
