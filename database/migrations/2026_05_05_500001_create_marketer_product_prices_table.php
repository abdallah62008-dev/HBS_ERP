<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketer_product_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('marketer_price_group_id')->constrained('marketer_price_groups')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->cascadeOnDelete();

            // What the marketer is charged for the product. The minimum_selling_price
            // is the floor for what they may sell to customers.
            $table->decimal('trade_price', 12, 2);
            $table->decimal('minimum_selling_price', 12, 2);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // One price-row per (group, product, variant). Short index name to
            // stay under MySQL's 64-char identifier limit.
            $table->unique(['marketer_price_group_id', 'product_id', 'product_variant_id'], 'mpp_grp_prod_var_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketer_product_prices');
    }
};
