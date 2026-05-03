<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_jobs', function (Blueprint $table) {
            $table->id();
            // Importer slug — e.g. "products", "customers", "stock", "expenses",
            // "price_updates". Maps to a class that implements the importer
            // contract (see ImporterRegistry).
            $table->string('import_type', 64)->index();

            $table->string('original_file_name', 255);
            $table->string('file_url', 1024);

            $table->enum('status', [
                'Uploaded', 'Validating', 'Ready', 'Processing',
                'Completed', 'Failed', 'Undone',
            ])->default('Uploaded')->index();

            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('successful_rows')->default(0);
            $table->unsignedInteger('failed_rows')->default(0);
            $table->unsignedInteger('duplicate_rows')->default(0);

            $table->string('error_report_url', 1024)->nullable();
            $table->boolean('can_undo')->default(false);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('undone_at')->nullable();
            $table->foreignId('undone_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_jobs');
    }
};
