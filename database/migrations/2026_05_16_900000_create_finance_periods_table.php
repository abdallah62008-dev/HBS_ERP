<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Finance Phase 5F — Finance Periods.
 *
 * A finance period is a date range (typically a month or quarter) that
 * can be open or closed. Closed periods block financial writes whose
 * `occurred_at` falls inside the range:
 *
 *   - cashbox opening balance / adjustment
 *   - cashbox transfer
 *   - collection posting to cashbox
 *   - expense posting to cashbox
 *   - refund payment (paid_at / occurred_at)
 *   - marketer payout payment (paid_at / occurred_at)
 *
 * Read-only views (reports, statements, drill-downs) remain available
 * regardless of period status.
 *
 * Distinct from the existing `fiscal_years` table — fiscal year is the
 * annual accounting boundary; finance period is the closing cadence
 * inside it.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('finance_periods', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // free-form, e.g. "May 2026", "Q2 2026"
            $table->date('start_date');
            $table->date('end_date');
            $table->enum('status', ['open', 'closed'])->default('open')->index();

            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();

            $table->foreignId('reopened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reopened_at')->nullable();

            $table->text('notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('start_date');
            $table->index('end_date');
            $table->index(['start_date', 'end_date'], 'fp_range_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_periods');
    }
};
