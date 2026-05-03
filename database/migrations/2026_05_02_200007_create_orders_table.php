<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number', 32)->unique();
            $table->foreignId('fiscal_year_id')->constrained('fiscal_years');
            $table->foreignId('customer_id')->constrained('customers');

            // marketer_id wires up to the marketers table in Phase 5. Column
            // exists now so order creation / queries can refer to it; the
            // FK constraint is added in a Phase 5 migration.
            $table->unsignedBigInteger('marketer_id')->nullable()->index();

            $table->string('source', 64)->nullable()->index();

            // Order workflow per 04_BUSINESS_WORKFLOWS.md.
            $table->enum('status', [
                'New', 'Pending Confirmation', 'Confirmed', 'Ready to Pack',
                'Packed', 'Ready to Ship', 'Shipped', 'Out for Delivery',
                'Delivered', 'Returned', 'Cancelled', 'On Hold', 'Need Review',
            ])->default('New')->index();

            $table->enum('collection_status', [
                'Not Collected', 'Collected', 'Partially Collected',
                'Pending Settlement', 'Settlement Received', 'Rejected', 'Refunded',
            ])->default('Not Collected')->index();

            $table->enum('shipping_status', [
                'Not Shipped', 'Assigned', 'Picked Up', 'In Transit',
                'Out for Delivery', 'Delivered', 'Returned', 'Delayed', 'Lost',
            ])->default('Not Shipped')->index();

            // Snapshot of customer/address at the time the order was placed.
            $table->string('customer_name');
            $table->string('customer_phone', 32)->index();
            $table->text('customer_address');
            $table->string('city');
            $table->string('governorate')->nullable();
            $table->string('country');

            // Money — all server-computed (see OrderService in later phases).
            $table->string('currency_code', 8);
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('shipping_amount', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('extra_fees', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->decimal('cod_amount', 12, 2)->default(0);
            $table->decimal('product_cost_total', 12, 2)->default(0);
            $table->decimal('marketer_trade_total', 12, 2)->default(0);
            $table->decimal('gross_profit', 12, 2)->default(0);
            $table->decimal('net_profit', 12, 2)->default(0);

            // Risk / dedupe snapshot — written by services on create.
            $table->unsignedInteger('customer_risk_score')->default(0);
            $table->enum('customer_risk_level', ['Low', 'Medium', 'High'])->default('Low')->index();
            $table->unsignedInteger('duplicate_score')->default(0);

            $table->text('notes')->nullable();
            $table->text('internal_notes')->nullable();

            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('packed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('packed_at')->nullable();
            $table->foreignId('shipped_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('returned_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            // Common filter combos
            $table->index(['status', 'created_at']);
            $table->index(['marketer_id', 'status']);
            $table->index(['shipping_status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
