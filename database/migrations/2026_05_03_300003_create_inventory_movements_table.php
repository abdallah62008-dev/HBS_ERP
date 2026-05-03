<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The single source of truth for stock levels.
 *
 * Stock-on-hand and available stock are derived by SUM-ing this table —
 * no separate "current quantity" column on `products` per
 * 04_BUSINESS_WORKFLOWS.md §13.
 *
 * The `quantity` column is signed: positive for stock-IN movements
 * (Purchase, Return To Stock, Opening Balance, Transfer In, positive
 * Adjustment), negative for stock-OUT movements (Ship, Return Damaged,
 * Transfer Out, negative Adjustment). A `Reserve` movement is signed
 * positive on `reserved_quantity` (the available-stock query subtracts
 * outstanding reservations).
 *
 * `reference_type` / `reference_id` link a movement to the document
 * that produced it (Order, PurchaseInvoice, StockAdjustment, etc.) so
 * we can trace any stock change back to its origin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants');
            $table->foreignId('warehouse_id')->constrained();

            $table->enum('movement_type', [
                'Purchase',
                'Reserve',
                'Release Reservation',
                'Ship',
                'Return To Stock',
                'Return Damaged',
                'Adjustment',
                'Transfer In',
                'Transfer Out',
                'Opening Balance',
                'Stock Count Correction',
            ])->index();

            // Signed quantity: positive = stock in, negative = stock out.
            // Reserve / Release Reservation use positive numbers and the
            // available-stock query treats reservation rows specially.
            $table->integer('quantity');

            $table->decimal('unit_cost', 12, 2)->nullable();

            // Polymorphic reference back to the producing document.
            $table->string('reference_type', 100)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();

            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent()->index();

            // Hot-path indexes for stock queries (custom short names —
            // MySQL caps identifiers at 64 chars).
            $table->index(['product_id', 'warehouse_id', 'movement_type'], 'im_prod_wh_type_idx');
            $table->index(['product_variant_id', 'warehouse_id', 'movement_type'], 'im_var_wh_type_idx');
            $table->index(['reference_type', 'reference_id'], 'im_ref_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
    }
};
