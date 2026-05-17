<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Orders & Products P-1 — Brand foundation.
 *
 * Additive only. Existing products survive with brand_id = NULL (the
 * FK is added in a sibling migration that runs immediately after).
 * No backfill is forced; operators populate the brand list and
 * back-tag SKUs at their own pace.
 *
 * Schema mirrors docs/orders-products/PRODUCT_MASTER_DATA_ROADMAP.md §3,
 * with the standard audit columns (created_by/updated_by) that the rest
 * of the project uses on user-curated master data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('image_url')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Lookup pattern: list active brands sorted for dropdowns.
            $table->index(['is_active', 'sort_order'], 'brands_active_sort_index');
            // Free-text name lookup for search.
            $table->index('name', 'brands_name_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brands');
    }
};
