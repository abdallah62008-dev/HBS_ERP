<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Created BEFORE expenses so expenses.related_campaign_id can FK against it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ad_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('platform', 64)->index();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->date('start_date')->index();
            $table->date('end_date')->nullable();

            $table->decimal('budget', 12, 2)->default(0);
            $table->decimal('spend', 12, 2)->default(0);

            $table->unsignedInteger('orders_count')->default(0);
            $table->unsignedInteger('delivered_orders_count')->default(0);
            $table->unsignedInteger('returned_orders_count')->default(0);

            $table->decimal('revenue', 12, 2)->default(0);
            $table->decimal('gross_profit', 12, 2)->default(0);
            $table->decimal('net_profit', 12, 2)->default(0);
            $table->decimal('cost_per_order', 12, 2)->default(0);
            $table->decimal('roas', 10, 2)->default(0);

            $table->enum('status', ['Active', 'Paused', 'Ended'])->default('Active')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_campaigns');
    }
};
