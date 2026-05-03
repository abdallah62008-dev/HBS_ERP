<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shipping_company_id')->constrained();
            $table->string('tracking_number', 64)->nullable()->index();

            $table->enum('shipping_status', [
                'Not Shipped', 'Assigned', 'Picked Up', 'In Transit',
                'Out for Delivery', 'Delivered', 'Returned', 'Delayed', 'Lost',
            ])->default('Assigned')->index();

            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('picked_up_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->text('delayed_reason')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // An order has at most one active shipment at a time. We allow
            // multiple historical rows but the active one is tagged by the
            // index lookup.
            $table->index(['order_id', 'shipping_status'], 'shp_order_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipments');
    }
};
