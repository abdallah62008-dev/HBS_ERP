<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Finance Phase 5D — Add (nullable) source linkage to marketer_transactions.
 *
 * Lets us trace where an Adjustment or Payout row came from:
 *   - source_type='refund', source_id=<refund.id>            (Layer B reversal)
 *   - source_type='marketer_payout', source_id=<payout.id>   (Layer A mirror row)
 *
 * The columns are nullable and indexed but never required, so the
 * existing `MarketerWalletService::syncFromOrder/payout/adjust` paths
 * keep working unchanged.
 *
 * Idempotency: a Layer B reversal checks for an existing row with
 * (source_type='refund', source_id=$refund->id) before writing a new
 * one, so a paid refund cannot trigger two reversal Adjustment rows
 * even if `RefundService::pay()` is called twice (which is already
 * blocked by the canBePaid guard, but defence-in-depth still helps).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('marketer_transactions', function (Blueprint $table) {
            if (! Schema::hasColumn('marketer_transactions', 'source_type')) {
                $table->string('source_type', 64)->nullable()->after('notes');
            }
            if (! Schema::hasColumn('marketer_transactions', 'source_id')) {
                $table->unsignedBigInteger('source_id')->nullable()->after('source_type');
            }
            $table->index(['source_type', 'source_id'], 'mt_source_idx');
        });
    }

    public function down(): void
    {
        Schema::table('marketer_transactions', function (Blueprint $table) {
            $table->dropIndex('mt_source_idx');
            if (Schema::hasColumn('marketer_transactions', 'source_id')) {
                $table->dropColumn('source_id');
            }
            if (Schema::hasColumn('marketer_transactions', 'source_type')) {
                $table->dropColumn('source_type');
            }
        });
    }
};
