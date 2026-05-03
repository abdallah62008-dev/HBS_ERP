<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('year_end_closings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fiscal_year_id')->constrained('fiscal_years');
            $table->foreignId('new_fiscal_year_id')->nullable()->constrained('fiscal_years');

            $table->enum('status', ['Draft', 'Processing', 'Completed', 'Failed'])
                ->default('Draft')->index();

            $table->foreignId('backup_id')->nullable()->constrained('backup_logs')->nullOnDelete();

            $table->string('closing_report_pdf_url', 1024)->nullable();
            $table->string('closing_report_excel_url', 1024)->nullable();

            $table->boolean('stock_carried_forward')->default(false);
            $table->boolean('marketer_balances_carried_forward')->default(false);
            $table->boolean('supplier_balances_carried_forward')->default(false);
            $table->boolean('pending_collections_carried_forward')->default(false);

            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('completed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('year_end_closings');
    }
};
