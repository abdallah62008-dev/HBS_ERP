<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('primary_phone', 32)->index();
            $table->string('secondary_phone', 32)->nullable()->index();
            $table->string('email')->nullable();
            $table->string('city');
            $table->string('governorate')->nullable();
            $table->string('country');
            $table->text('default_address');
            $table->unsignedInteger('risk_score')->default(0);
            $table->enum('risk_level', ['Low', 'Medium', 'High'])->default('Low')->index();
            $table->enum('customer_type', ['Normal', 'VIP', 'Watchlist', 'Blacklist'])->default('Normal')->index();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            // Composite index for the most common dedupe lookup.
            $table->index(['primary_phone', 'city']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
