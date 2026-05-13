<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Finance Phase 3 — Collections × Cashboxes integration.
 *
 * Adds four nullable columns to `collections`:
 *   - cashbox_id            — where the money landed (or will land)
 *   - payment_method_id     — how the money moved
 *   - cashbox_transaction_id — the resulting ledger row (set on post)
 *   - cashbox_posted_at     — when the post happened
 *
 * All four are nullable so historical collections continue to load
 * cleanly. Nothing about the existing collection_status enum changes
 * — Phase 3 layers on top.
 *
 * Per docs/finance/PHASE_0_FINANCIAL_BUSINESS_RULES.md:
 *   - Direct payments (Cash / Visa / Bank Transfer / Vodafone Cash /
 *     Amazon Wallet / Noon Wallet) post to a cashbox immediately when
 *     the operator records them.
 *   - Courier COD is "Pending Settlement" until the courier remits;
 *     posting only happens at settlement time.
 *   - Double-posting is prevented by checking cashbox_transaction_id
 *     in the service layer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('collections', function (Blueprint $table) {
            $table->foreignId('cashbox_id')
                ->nullable()
                ->after('shipping_company_id')
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

            $table->index('cashbox_id', 'collections_cashbox_idx');
            $table->index('payment_method_id', 'collections_payment_method_idx');
            $table->index('cashbox_transaction_id', 'collections_cashbox_tx_idx');
            $table->index('cashbox_posted_at', 'collections_cashbox_posted_at_idx');
        });
    }

    public function down(): void
    {
        Schema::table('collections', function (Blueprint $table) {
            $table->dropIndex('collections_cashbox_idx');
            $table->dropIndex('collections_payment_method_idx');
            $table->dropIndex('collections_cashbox_tx_idx');
            $table->dropIndex('collections_cashbox_posted_at_idx');

            $table->dropConstrainedForeignId('cashbox_id');
            $table->dropConstrainedForeignId('payment_method_id');
            $table->dropConstrainedForeignId('cashbox_transaction_id');
            $table->dropColumn('cashbox_posted_at');
        });
    }
};
