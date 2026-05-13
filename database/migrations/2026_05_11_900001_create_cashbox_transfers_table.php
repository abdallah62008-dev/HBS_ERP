<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Finance Phase 2 — Cashbox Transfers.
 *
 * A transfer represents money moving between two cashboxes. Each row
 * spawns EXACTLY TWO `cashbox_transactions`:
 *   - one negative on `from_cashbox_id`
 *   - one positive on `to_cashbox_id`
 * Both linked via `cashbox_transactions.transfer_id = cashbox_transfers.id`.
 *
 * Per docs/finance/PHASE_0_FINANCIAL_BUSINESS_RULES.md:
 *   - from_cashbox_id != to_cashbox_id (service guard)
 *   - amount > 0
 *   - cashboxes must be active (service guard)
 *   - Service wraps everything in DB::transaction so partial failure
 *     can never leave one half of the pair behind.
 *   - No hard delete. Reversals are done by writing the opposite
 *     transfer.
 *   - Transfers are excluded from "net cash movement" reports later
 *     by filtering `cashbox_transactions.source_type='transfer'`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cashbox_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_cashbox_id')->constrained('cashboxes')->restrictOnDelete();
            $table->foreignId('to_cashbox_id')->constrained('cashboxes')->restrictOnDelete();
            $table->decimal('amount', 14, 2);
            $table->timestamp('occurred_at')->index();
            $table->text('reason')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('from_cashbox_id', 'cb_transfers_from_idx');
            $table->index('to_cashbox_id', 'cb_transfers_to_idx');
            $table->index('created_by', 'cb_transfers_created_by_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cashbox_transfers');
    }
};
