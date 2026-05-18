<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer C-5B — mark merged customers via tombstone fields.
 *
 * When a customer is merged INTO another, the source row stays alive
 * (NOT soft-deleted) but gains three fields:
 *
 *   - merged_into_customer_id — pointer to the surviving customer
 *   - merged_at               — when the merge happened
 *   - merged_by               — who executed the merge
 *
 * The C-2 duplicate-detection query filters on
 * `merged_into_customer_id IS NULL` so an already-merged source never
 * resurfaces as a duplicate of new arrivals.
 *
 * Additive only — every column is nullable. Pre-existing customer rows
 * land with all three NULL and behave exactly as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            if (! Schema::hasColumn('customers', 'merged_into_customer_id')) {
                $table->foreignId('merged_into_customer_id')
                    ->nullable()
                    ->after('deleted_by')
                    ->constrained('customers')
                    ->nullOnDelete();
            }
            if (! Schema::hasColumn('customers', 'merged_at')) {
                $table->timestamp('merged_at')->nullable()->after('merged_into_customer_id');
            }
            if (! Schema::hasColumn('customers', 'merged_by')) {
                $table->foreignId('merged_by')
                    ->nullable()
                    ->after('merged_at')
                    ->constrained('users')
                    ->nullOnDelete();
            }
        });

        // Index for the C-2 duplicate-detector and the "list merged
        // customers" admin view.
        try {
            Schema::table('customers', function (Blueprint $table) {
                $table->index('merged_into_customer_id', 'customers_merged_into_idx');
            });
        } catch (\Throwable $e) {
            // Already exists — ignore.
        }
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            try {
                $table->dropIndex('customers_merged_into_idx');
            } catch (\Throwable $e) {
                // ignore
            }
            foreach (['merged_by', 'merged_at', 'merged_into_customer_id'] as $col) {
                if (Schema::hasColumn('customers', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
