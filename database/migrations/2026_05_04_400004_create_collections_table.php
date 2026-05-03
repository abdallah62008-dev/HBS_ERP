<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained();
            $table->foreignId('shipping_company_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount_due', 12, 2);
            $table->decimal('amount_collected', 12, 2)->default(0);

            $table->enum('collection_status', [
                'Not Collected', 'Collected', 'Partially Collected',
                'Pending Settlement', 'Settlement Received', 'Rejected', 'Refunded',
            ])->default('Not Collected')->index();

            $table->string('settlement_reference', 128)->nullable()->index();
            $table->date('settlement_date')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // One COD record per order.
            $table->unique('order_id');
            $table->index(['shipping_company_id', 'collection_status'], 'col_co_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collections');
    }
};
