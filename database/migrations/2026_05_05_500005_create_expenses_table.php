<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expense_category_id')->constrained();
            $table->string('title');
            $table->decimal('amount', 12, 2);
            $table->string('currency_code', 8);
            $table->date('expense_date')->index();
            $table->string('payment_method', 64)->nullable();

            $table->foreignId('related_order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->foreignId('related_campaign_id')->nullable()->constrained('ad_campaigns')->nullOnDelete();

            $table->text('notes')->nullable();
            $table->string('attachment_url')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['expense_date', 'expense_category_id'], 'exp_date_cat_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
