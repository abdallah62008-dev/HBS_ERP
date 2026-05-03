<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('sku', 64)->unique();
            $table->string('barcode', 64)->nullable()->index();
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();

            // supplier_id is added in Phase 3 once `suppliers` exists. The
            // column is declared here so order/import code can reference it,
            // but the FK constraint is wired up in 2026_05_03_200000_add_supplier_id_fk.
            $table->unsignedBigInteger('supplier_id')->nullable()->index();

            $table->string('image_url')->nullable();
            $table->text('description')->nullable();

            $table->decimal('cost_price', 12, 2)->default(0);
            $table->decimal('selling_price', 12, 2)->default(0);
            $table->decimal('marketer_trade_price', 12, 2)->default(0);
            $table->decimal('minimum_selling_price', 12, 2)->default(0);

            $table->boolean('tax_enabled')->default(false);
            $table->decimal('tax_rate', 5, 2)->default(0);

            $table->integer('reorder_level')->default(0);
            $table->enum('status', ['Active', 'Inactive', 'Out of Stock', 'Discontinued'])
                ->default('Active')
                ->index();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
