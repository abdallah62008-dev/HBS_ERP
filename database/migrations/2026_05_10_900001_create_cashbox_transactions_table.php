<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Finance Phase 1 — Cashbox Transactions Ledger.
 *
 * The append-only ledger for every money movement. Every transaction is
 * signed (+inflow, -outflow), timestamped (`occurred_at`), attributable
 * to a user (`created_by`), and linked to its source domain object via
 * `source_type` + `source_id` (polymorphic-by-convention).
 *
 * Phase 1 allows two `source_type` values:
 *   - `opening_balance` (auto-written on cashbox creation when opening_balance != 0)
 *   - `adjustment`      (manual entry by a user with cashbox_transactions.create)
 *
 * Later phases extend this column with: `collection`, `expense`, `refund`,
 * `transfer`, `marketer_payout`. No schema change is needed — the column
 * is a free-form string and the service layer governs valid values.
 *
 * `transfer_id` and `payment_method_id` are added now (nullable) so
 * Phases 2+ do not need to alter the table.
 *
 * Per docs/finance/PHASE_0_FINANCIAL_BUSINESS_RULES.md:
 *   - Append-only — no soft delete, no UPDATE of amount or direction.
 *   - Corrections are done by INSERTing a new opposite-signed row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cashbox_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cashbox_id')->constrained('cashboxes')->restrictOnDelete();
            $table->enum('direction', ['in', 'out']);
            $table->decimal('amount', 14, 2); // signed: + inflow, - outflow
            $table->timestamp('occurred_at')->index();

            $table->string('source_type', 40)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();

            // Reserved nullable FKs for Phase 2 (transfers) and Phase 2+ (payment methods).
            // Added here so later phases do not need to ALTER the table.
            $table->unsignedBigInteger('transfer_id')->nullable();
            $table->unsignedBigInteger('payment_method_id')->nullable();

            $table->text('notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps(); // updated_at exists but is intentionally never written by code

            $table->index(['cashbox_id', 'occurred_at'], 'cashbox_tx_cashbox_occurred_idx');
            $table->index(['source_type', 'source_id'], 'cashbox_tx_source_idx');
            $table->index('transfer_id', 'cashbox_tx_transfer_idx');
            $table->index('created_by', 'cashbox_tx_created_by_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cashbox_transactions');
    }
};
