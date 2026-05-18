<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer C-5B — duplicate merge log table.
 *
 * Records every executed merge: who, when, source, target, why,
 * affected-record counts per table, and a JSON payload that snapshots
 * the source customer's full profile + the lists of affected ids per
 * table. The payload is the foundation for a future rollback command
 * (deferred — see IMPLEMENTATION_PHASES.md C-5B section).
 *
 * FK on source/target/merged_by all use nullOnDelete so the merge log
 * survives even if a referenced row is later hard-deleted by a
 * super-admin emergency action.
 *
 * No `updated_at` — merges are immutable history. `created_at` is the
 * merge-execution timestamp.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_merges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('target_customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('merged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason');
            $table->unsignedInteger('affected_orders_count')->default(0);
            $table->unsignedInteger('affected_returns_count')->default(0);
            $table->unsignedInteger('affected_refunds_count')->default(0);
            $table->unsignedInteger('affected_notes_count')->default(0);
            $table->unsignedInteger('affected_addresses_count')->default(0);
            $table->unsignedInteger('affected_tags_count')->default(0);
            // JSON: { source_profile: {...}, affected_ids: { orders: [...], returns: [...], ... } }
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('source_customer_id', 'customer_merges_source_idx');
            $table->index('target_customer_id', 'customer_merges_target_idx');
            $table->index('merged_by', 'customer_merges_merged_by_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_merges');
    }
};
