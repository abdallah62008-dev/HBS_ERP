<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Orders & Products P-1 — link products to brands.
 *
 * `brand_id` is nullable so existing products remain valid. ON DELETE
 * SET NULL because removing a brand should not cascade-delete products;
 * the orphan products simply lose the brand attribution.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('brand_id')
                ->nullable()
                ->after('category_id')
                ->constrained('brands')
                ->nullOnDelete();

            $table->index('brand_id', 'products_brand_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['brand_id']);
            $table->dropIndex('products_brand_id_index');
            $table->dropColumn('brand_id');
        });
    }
};
