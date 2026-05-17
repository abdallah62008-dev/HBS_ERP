<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Orders & Products P-1 — Channel SKU mapping foundation.
 *
 * Maps one product variant to one external marketplace SKU. Mirrors
 * docs/orders-products/CHANNEL_SKU_AND_MARKETPLACE_MAPPING.md §3 with
 * one extension: `external_barcode` is added (nullable) because some
 * marketplaces print their own barcode alongside their SKU and ops
 * needs both for reconciliation.
 *
 * Unique `(product_variant_id, channel)` matches the doc — one mapping
 * row per (variant, channel) pair. Re-imports use ON DUPLICATE KEY
 * UPDATE semantics on this constraint.
 *
 * Channel is a string column rather than a hard DB enum so future
 * marketplaces (TikTok, Shopify, Sub-channel) can be added by app-level
 * validation without a schema change. The current valid list lives in
 * ProductChannelSku::CHANNELS and is enforced by the request validator.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_channel_skus', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_variant_id')
                ->constrained('product_variants')
                ->cascadeOnDelete();

            $table->string('channel', 32);
            $table->string('external_sku', 128);
            $table->string('external_barcode', 128)->nullable();
            $table->string('external_url', 1024)->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // One mapping per (variant, channel). Doc rationale: re-imports
            // are an UPSERT on this key; multiple mappings per channel on
            // the same variant are not a real-world need.
            $table->unique(['product_variant_id', 'channel'], 'pcs_variant_channel_unique');

            // Marketplace-order-import lookup: "find variant by Amazon's
            // SKU = B08N5WRWNW". Channel narrows the index further.
            $table->index(['channel', 'external_sku'], 'pcs_channel_extsku_index');
            // Free-text external SKU search (no channel known).
            $table->index('external_sku', 'pcs_extsku_index');
            $table->index('external_barcode', 'pcs_extbarcode_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_channel_skus');
    }
};
