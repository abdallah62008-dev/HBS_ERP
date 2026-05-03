<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_job_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_job_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->json('raw_data_json');

            $table->enum('status', ['Success', 'Failed', 'Duplicate', 'Skipped'])
                ->default('Failed')->index();

            $table->text('error_message')->nullable();

            // Polymorphic ref to the record this row created (for "undo" + audit).
            $table->string('created_record_type', 100)->nullable();
            $table->unsignedBigInteger('created_record_id')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['import_job_id', 'status'], 'ijr_job_status_idx');
            $table->index(['created_record_type', 'created_record_id'], 'ijr_target_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_job_rows');
    }
};
