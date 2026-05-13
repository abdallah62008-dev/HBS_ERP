<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Finance Phase 4 — Expenses × Cashboxes integration.
 *
 * Adds four nullable columns to `expenses`, mirroring the Phase 3
 * collections pattern:
 *   - cashbox_id            — where the money came from
 *   - payment_method_id     — how it left the business (structured)
 *   - cashbox_transaction_id — the resulting OUT ledger row
 *   - cashbox_posted_at     — when the post happened
 *
 * The legacy free-text `payment_method` column is intentionally kept
 * for historical compatibility. New expenses use `payment_method_id`.
 *
 * All four are nullable so historical expense rows continue to load
 * cleanly. Phase 4 does NOT backfill — historical rows stay null.
 *
 * Per docs/finance/PHASE_0_FINANCIAL_BUSINESS_RULES.md §5:
 *   - New expenses require cashbox_id + payment_method_id (UI + service guard).
 *   - Saving a new expense writes one cashbox_transactions row (OUT, signed negative).
 *   - Posted expenses are append-only: amount / cashbox / payment_method
 *     / expense_date cannot be edited; soft-delete is also blocked.
 *     Corrections wait for the refund / adjustment phase.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->foreignId('cashbox_id')
                ->nullable()
                ->after('payment_method')
                ->constrained('cashboxes')
                ->nullOnDelete();

            $table->foreignId('payment_method_id')
                ->nullable()
                ->after('cashbox_id')
                ->constrained('payment_methods')
                ->nullOnDelete();

            $table->foreignId('cashbox_transaction_id')
                ->nullable()
                ->after('payment_method_id')
                ->constrained('cashbox_transactions')
                ->nullOnDelete();

            $table->timestamp('cashbox_posted_at')->nullable()->after('cashbox_transaction_id');

            $table->index('cashbox_id', 'expenses_cashbox_idx');
            $table->index('payment_method_id', 'expenses_payment_method_idx');
            $table->index('cashbox_transaction_id', 'expenses_cashbox_tx_idx');
            $table->index('cashbox_posted_at', 'expenses_cashbox_posted_at_idx');
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropIndex('expenses_cashbox_idx');
            $table->dropIndex('expenses_payment_method_idx');
            $table->dropIndex('expenses_cashbox_tx_idx');
            $table->dropIndex('expenses_cashbox_posted_at_idx');

            $table->dropConstrainedForeignId('cashbox_id');
            $table->dropConstrainedForeignId('payment_method_id');
            $table->dropConstrainedForeignId('cashbox_transaction_id');
            $table->dropColumn('cashbox_posted_at');
        });
    }
};
