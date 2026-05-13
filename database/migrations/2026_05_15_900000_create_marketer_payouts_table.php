<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Finance Phase 5D — Marketer Payouts (workflow envelope).
 *
 * The existing `marketer_transactions` table stays the canonical ledger.
 * This table holds the *workflow* (requested → approved/rejected → paid)
 * and the cashbox linkage when paid. When a payout is paid the service
 * writes two rows:
 *
 *   1. cashbox_transactions  — source_type='marketer_payout', signed OUT
 *   2. marketer_transactions — type='Payout', status='Paid' (wallet sum)
 *
 * Both ids are linked back here via cashbox_transaction_id and
 * marketer_transaction_id, so the audit trail is bidirectional.
 *
 * Mirrors the Phase 5A `refunds` schema (reserved nullable paid_* +
 * cashbox_* columns at creation time so Phase 5B-style hardening lives
 * on day one).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('marketer_payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('marketer_id')->constrained()->cascadeOnDelete();

            // Cashbox linkage filled when status='paid' only.
            $table->foreignId('cashbox_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payment_method_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('cashbox_transaction_id')->nullable()->constrained('cashbox_transactions')->nullOnDelete();
            $table->foreignId('marketer_transaction_id')->nullable()->constrained('marketer_transactions')->nullOnDelete();

            $table->decimal('amount', 12, 2);
            $table->enum('status', ['requested', 'approved', 'rejected', 'paid'])
                ->default('requested')
                ->index();

            $table->text('notes')->nullable();

            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('paid_at')->nullable();

            $table->timestamps();

            $table->index(['marketer_id', 'status']);
            $table->index('paid_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketer_payouts');
    }
};
