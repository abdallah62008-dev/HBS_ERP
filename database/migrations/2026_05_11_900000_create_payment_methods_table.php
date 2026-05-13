<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Finance Phase 2 — Payment Methods lookup table.
 *
 * Small reference table describing HOW money moves (Cash, Visa/POS,
 * Vodafone Cash, Bank Transfer, Courier COD, Amazon Wallet, …).
 *
 * Phase 2 does not yet wire payment_method_id into collections or
 * expenses — that integration lands in Phase 3 / Phase 4. The reserved
 * `payment_method_id` column already exists on `cashbox_transactions`
 * (added in Phase 1's migration) and the FK relationship is documented
 * but not formally constrained — service-layer code validates ids.
 *
 * Per docs/finance/PHASE_0_FINANCIAL_BUSINESS_RULES.md:
 *   - No hard delete. Deactivation only.
 *   - default_cashbox_id is OPTIONAL. The cashbox may not exist at seed
 *     time, so the seeder must not require it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60);
            $table->string('code', 40)->unique();
            $table->enum('type', [
                'cash', 'card', 'bank_transfer', 'digital_wallet',
                'marketplace', 'courier_cod', 'other',
            ]);
            $table->foreignId('default_cashbox_id')->nullable()->constrained('cashboxes')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->text('description')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['type', 'is_active'], 'payment_methods_type_active_idx');
            $table->index('default_cashbox_id', 'payment_methods_default_cb_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_methods');
    }
};
