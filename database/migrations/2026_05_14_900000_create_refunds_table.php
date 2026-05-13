<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Finance Phase 5A — Refunds Foundation.
 *
 * One row per refund decision. Phase 5A is paperwork-only:
 *   Requested → Approved
 *   Requested → Rejected
 * No `Paid` flow in 5A. No cashbox transaction is created by refund
 * actions. The `paid_*` and cashbox-related columns are seeded as
 * NULLable now (future-reserved) so Phase 5B does not need an ALTER.
 *
 * The `paid` enum value is included in the `status` column for the
 * same reason — future-reserved, but never written by Phase 5A code.
 *
 * Per docs/finance/PHASE_0_DATABASE_DESIGN_DRAFT.md §1.5:
 *   - No soft-delete on refunds. Rejected refunds stay with
 *     `status='Rejected'` (they ARE the rejection record).
 *   - Phase 5A allows DELETE only while status='requested' — guarded
 *     in the controller and at the model layer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table) {
            $table->id();

            // Linkage — all nullable so a refund can be standalone
            // (goodwill / mis-charge) or tied to existing finance objects.
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->foreignId('collection_id')->nullable()->constrained('collections')->nullOnDelete();
            $table->foreignId('order_return_id')->nullable()->constrained('returns')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();

            $table->decimal('amount', 12, 2);
            $table->text('reason')->nullable();

            // `paid` is future-reserved (Phase 5B). 5A code never writes it.
            $table->enum('status', ['requested', 'approved', 'rejected', 'paid'])
                ->default('requested')
                ->index();

            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();

            // ── Future-reserved Phase 5B columns (kept NULLable; never
            //    written by Phase 5A code, so adding them now avoids a
            //    follow-up ALTER and keeps Phase 5B purely behavioural).
            $table->foreignId('cashbox_id')->nullable()->constrained('cashboxes')->nullOnDelete();
            $table->foreignId('payment_method_id')->nullable()->constrained('payment_methods')->nullOnDelete();
            $table->foreignId('cashbox_transaction_id')->nullable()->constrained('cashbox_transactions')->nullOnDelete();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();

            $table->timestamps();

            $table->index('order_id', 'refunds_order_idx');
            $table->index('collection_id', 'refunds_collection_idx');
            $table->index('order_return_id', 'refunds_order_return_idx');
            $table->index('customer_id', 'refunds_customer_idx');
            $table->index('approved_at', 'refunds_approved_at_idx');
            $table->index('rejected_at', 'refunds_rejected_at_idx');
            $table->index('paid_at', 'refunds_paid_at_idx');
            $table->index(['collection_id', 'status'], 'refunds_collection_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
    }
};
