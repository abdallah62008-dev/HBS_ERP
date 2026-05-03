<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lifecycle:
 *   Order created with marketer  → 1 row, type=Expected Profit, status=Expected
 *   Order shipped                 → same row updated to Pending Profit / Pending
 *   Order delivered               → same row updated to Earned Profit / Approved
 *   Order cancelled or returned   → same row updated to Cancelled Profit / Cancelled
 *
 * Payouts and adjustments are SEPARATE rows so the profit row's history
 * stays clean and auditors can see each money-out event independently.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketer_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('marketer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained();

            $table->enum('transaction_type', [
                'Expected Profit', 'Pending Profit', 'Earned Profit',
                'Cancelled Profit', 'Payout', 'Adjustment',
            ])->index();

            // Snapshot of the components that fed the formula. Stored
            // explicitly so a later audit can reconstruct net_profit
            // without trusting the order's mutable totals.
            $table->decimal('selling_price', 12, 2)->default(0);
            $table->decimal('trade_product_price', 12, 2)->default(0);
            $table->decimal('shipping_amount', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('extra_fees', 12, 2)->default(0);
            $table->decimal('net_profit', 12, 2)->default(0);

            $table->enum('status', ['Expected', 'Pending', 'Approved', 'Paid', 'Cancelled'])
                ->default('Expected')->index();

            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['marketer_id', 'transaction_type'], 'mt_marketer_type_idx');
            $table->index(['marketer_id', 'status'], 'mt_marketer_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketer_transactions');
    }
};
