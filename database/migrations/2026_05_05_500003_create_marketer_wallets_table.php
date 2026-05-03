<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketer_wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('marketer_id')->unique()->constrained()->cascadeOnDelete();

            // All four totals are derived sums; balance = total_earned - total_paid.
            // We persist them as snapshots so the wallet page is fast and the
            // numbers shown to a marketer don't drift when running tallies are
            // mid-update.
            $table->decimal('total_expected', 12, 2)->default(0);
            $table->decimal('total_pending', 12, 2)->default(0);
            $table->decimal('total_earned', 12, 2)->default(0);
            $table->decimal('total_paid', 12, 2)->default(0);
            $table->decimal('balance', 12, 2)->default(0);

            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketer_wallets');
    }
};
