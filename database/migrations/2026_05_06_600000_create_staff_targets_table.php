<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Free-form so the operator can name new metrics later. Phase 6
            // implements: 'Confirmed Orders', 'Delivered Orders',
            // 'Sales Amount', 'Low Return Rate'.
            $table->string('target_type', 64)->index();
            $table->enum('target_period', ['Daily', 'Weekly', 'Monthly', 'Quarterly'])->default('Monthly');
            $table->decimal('target_value', 12, 2);
            $table->decimal('achieved_value', 12, 2)->default(0);
            $table->date('start_date')->index();
            $table->date('end_date');
            $table->enum('status', ['Active', 'Completed', 'Cancelled'])->default('Active')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'status'], 'st_user_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_targets');
    }
};
