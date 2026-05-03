<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('code', 32)->unique();
            $table->foreignId('price_group_id')->constrained('marketer_price_groups');
            $table->string('phone', 32)->nullable();
            $table->enum('status', ['Active', 'Inactive', 'Suspended'])->default('Active')->index();

            // Per-marketer overrides for the global profit-formula behaviour.
            $table->boolean('shipping_deducted')->default(true);
            $table->boolean('tax_deducted')->default(true);
            $table->boolean('commission_after_delivery_only')->default(true);

            $table->enum('settlement_cycle', ['Daily', 'Weekly', 'Monthly'])->default('Weekly');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketers');
    }
};
