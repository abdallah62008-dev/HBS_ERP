<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('export_logs', function (Blueprint $table) {
            $table->id();
            $table->string('export_type', 64)->index();
            $table->json('filters_json')->nullable();
            $table->string('file_url', 1024)->nullable();
            $table->unsignedInteger('rows_count')->default(0);
            $table->foreignId('exported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('export_logs');
    }
};
