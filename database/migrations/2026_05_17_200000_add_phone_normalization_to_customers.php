<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Orders & Products O-2 — Phone Normalization & WhatsApp readiness.
 *
 * Additive only. The triple `country_code` + `local_phone` +
 * `normalized_phone` is added alongside the existing legacy phone
 * columns; nothing is dropped or renamed. The legacy `primary_phone`
 * column stays as a display + back-compat field.
 *
 * `normalized_phone` is INDEXED (not unique) — backfilling existing rows
 * will surface real duplicates that an operator must merge. Promoting to
 * a unique constraint is a follow-up phase once the merge workflow lands.
 *
 * Mirror columns are added for `secondary_phone` so the same dedupe /
 * WhatsApp logic applies to alternate numbers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            // Primary phone triple
            if (! Schema::hasColumn('customers', 'country_code')) {
                $table->string('country_code', 8)->nullable()->after('primary_phone');
            }
            if (! Schema::hasColumn('customers', 'local_phone')) {
                $table->string('local_phone', 32)->nullable()->after('country_code');
            }
            if (! Schema::hasColumn('customers', 'normalized_phone')) {
                $table->string('normalized_phone', 20)->nullable()->after('local_phone');
            }

            // Secondary phone triple
            if (! Schema::hasColumn('customers', 'secondary_country_code')) {
                $table->string('secondary_country_code', 8)->nullable()->after('secondary_phone');
            }
            if (! Schema::hasColumn('customers', 'secondary_local_phone')) {
                $table->string('secondary_local_phone', 32)->nullable()->after('secondary_country_code');
            }
            if (! Schema::hasColumn('customers', 'secondary_normalized_phone')) {
                $table->string('secondary_normalized_phone', 20)->nullable()->after('secondary_local_phone');
            }
        });

        // Indexes added separately so each `addIndex` survives partial
        // re-runs after a manual rollback. Wrapped in idempotent checks
        // because SQLite (test DB) doesn't expose `hasIndex()` reliably
        // — we catch the duplicate exception instead.
        $this->addIndexSafely('customers', 'normalized_phone', 'customers_normalized_phone_index');
        $this->addIndexSafely('customers', 'secondary_normalized_phone', 'customers_secondary_normalized_phone_index');
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $this->dropIndexSafely($table, 'customers_normalized_phone_index');
            $this->dropIndexSafely($table, 'customers_secondary_normalized_phone_index');

            foreach ([
                'normalized_phone', 'local_phone', 'country_code',
                'secondary_normalized_phone', 'secondary_local_phone', 'secondary_country_code',
            ] as $col) {
                if (Schema::hasColumn('customers', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }

    /**
     * Add an index without failing on re-run.
     */
    private function addIndexSafely(string $tableName, string $column, string $indexName): void
    {
        try {
            Schema::table($tableName, function (Blueprint $table) use ($column, $indexName) {
                $table->index($column, $indexName);
            });
        } catch (\Throwable $e) {
            // Index already exists — safe to ignore.
        }
    }

    /**
     * Drop an index without failing if it isn't there.
     */
    private function dropIndexSafely(Blueprint $table, string $indexName): void
    {
        try {
            $table->dropIndex($indexName);
        } catch (\Throwable $e) {
            // No such index — safe to ignore.
        }
    }
};
