<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Polymorphic file attachments. Used in Phase 4 for pre-shipping photos
 * and shipping-label PDFs, and reused by later phases for purchase
 * invoice scans, complaint attachments, payment proofs, etc.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->string('related_type', 100)->index();
            $table->unsignedBigInteger('related_id')->index();
            $table->string('file_name');
            $table->string('file_url', 1024);
            $table->string('file_type', 64)->nullable();
            $table->unsignedBigInteger('file_size_bytes')->nullable();

            // Free-form tag so consumers can filter (e.g. "Pre Shipping Photo",
            // "Shipping Label", "Purchase Invoice", "Return Photo").
            $table->string('attachment_type', 64)->index();

            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['related_type', 'related_id', 'attachment_type'], 'att_target_type_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};
