<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `returns` is the spec's chosen table name (02_DATABASE_SCHEMA.md).
 * MySQL allows it; the model is `OrderReturn` because PHP would refuse
 * a class literally named `Return`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained();
            $table->foreignId('customer_id')->constrained();
            $table->foreignId('shipping_company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('return_reason_id')->constrained();

            $table->enum('product_condition', ['Good', 'Damaged', 'Missing Parts', 'Unknown'])->default('Unknown');

            $table->enum('return_status', ['Pending', 'Received', 'Inspected', 'Restocked', 'Damaged', 'Closed'])
                ->default('Pending')->index();

            $table->decimal('refund_amount', 12, 2)->default(0);
            $table->decimal('shipping_loss_amount', 12, 2)->default(0);
            $table->boolean('restockable')->default(false);

            $table->text('notes')->nullable();
            $table->foreignId('inspected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('inspected_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Most queries hit by order or status — keep them indexed.
            $table->index(['order_id', 'return_status'], 'ret_order_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('returns');
    }
};
