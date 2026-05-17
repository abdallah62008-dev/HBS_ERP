<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer C-4A — Customer Notes foundation.
 *
 * Additive only. Mirrors the `order_notes` schema (one note row per
 * actor / per moment) so the C-3 activity timeline can pick up
 * `customer_note_added` events from a single indexed source.
 *
 * The existing `customers.notes` text column is NOT touched — it stays
 * as a single-bucket free-text field for back-compat. The new table is
 * supplementary: list of structured notes with actor + timestamp +
 * internal/external flag.
 *
 * Reversible cleanly via `dropIfExists` — no data the rest of the app
 * relies on yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->text('note');
            // Default to internal because the customer-facing surface
            // isn't built yet. When external notes ship, the column is
            // already in place.
            $table->boolean('is_internal')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Indexed lookup for the Customer Show panel (newest-first
            // per customer) and the C-3 timeline merge.
            $table->index(['customer_id', 'created_at'], 'customer_notes_customer_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_notes');
    }
};
