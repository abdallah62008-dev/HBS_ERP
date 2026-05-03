<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('requested_by')->constrained('users');

            // Approval type — e.g. "Delete Order", "Edit Confirmed Order Price",
            // "Manual Inventory Adjustment", "Pay Marketer", etc. Each type
            // has a registered handler in ApprovalService.
            $table->string('approval_type', 64)->index();

            // Polymorphic ref to the target record.
            $table->string('related_type', 100)->nullable();
            $table->unsignedBigInteger('related_id')->nullable();

            // Snapshot of state before/after for audit + execution.
            $table->json('old_values_json')->nullable();
            $table->json('new_values_json')->nullable();

            $table->text('reason')->nullable();

            $table->enum('status', ['Pending', 'Approved', 'Rejected'])->default('Pending')->index();

            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->timestamps();

            $table->index(['related_type', 'related_id'], 'ar_target_idx');
            $table->index(['approval_type', 'status'], 'ar_type_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_requests');
    }
};
