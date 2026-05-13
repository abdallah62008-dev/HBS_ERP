<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Finance Phase 1 — Cashboxes Foundation.
 *
 * Cashboxes are the named places where money lives: Main Cash, Visa POS,
 * Vodafone Cash, Bank Account, Amazon Wallet, Noon Wallet, Courier COD
 * Wallet. Phase 1 is a self-contained module — Phases 3+ integrate
 * collections, expenses, and refunds.
 *
 * Per docs/finance/PHASE_0_DATABASE_DESIGN_DRAFT.md:
 *   - No current_balance column — balance is always SUM(cashbox_transactions.amount).
 *   - Soft delete intentionally omitted — deactivation replaces deletion.
 *   - `opening_balance` is editable only while no transactions exist
 *     (enforced in service layer; migration permits the column).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cashboxes', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80)->unique();
            $table->enum('type', ['cash', 'bank', 'digital_wallet', 'marketplace', 'courier_cod'])->index();
            $table->string('currency_code', 8);
            $table->decimal('opening_balance', 14, 2)->default(0);
            $table->boolean('allow_negative_balance')->default(true);
            $table->boolean('is_active')->default(true);
            $table->text('description')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['type', 'is_active'], 'cashboxes_type_active_idx');
            $table->index('currency_code', 'cashboxes_currency_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cashboxes');
    }
};
