<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipping_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipping_company_id')->constrained()->cascadeOnDelete();
            $table->string('country');
            $table->string('governorate')->nullable();
            $table->string('city');
            $table->decimal('base_cost', 12, 2);
            $table->decimal('cod_fee', 12, 2)->default(0);
            $table->decimal('return_fee', 12, 2)->default(0);
            $table->unsignedSmallInteger('estimated_days')->nullable();
            $table->enum('status', ['Active', 'Inactive'])->default('Active');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Unique combination of company + destination — short name to
            // stay under MySQL's 64-char identifier limit.
            $table->unique(['shipping_company_id', 'country', 'governorate', 'city'], 'sr_company_dest_unique');
            $table->index(['country', 'governorate', 'city'], 'sr_dest_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipping_rates');
    }
};
