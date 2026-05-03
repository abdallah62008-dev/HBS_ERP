<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            // A notification can target a single user OR a whole role
            // (broadcast). Exactly one of (user_id, role_id) is set.
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->nullable()->constrained()->cascadeOnDelete();

            $table->string('title');
            $table->text('message');
            $table->string('type', 64)->index();

            // Optional URL the bell-icon click should jump to (e.g. /orders/42).
            $table->string('action_url', 1024)->nullable();

            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();

            $table->index(['user_id', 'read_at'], 'notif_user_read_idx');
            $table->index(['role_id', 'read_at'], 'notif_role_read_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
