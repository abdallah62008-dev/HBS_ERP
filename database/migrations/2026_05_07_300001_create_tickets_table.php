<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6.2 — Tickets module.
 *
 * Schema for an internal-support / customer-issue ticketing module.
 * Pending Task #6 from the project handoff: "Tickets module (entire CRUD —
 * schema in spec, never built)".
 *
 * This migration only creates the `tickets` table; the model, controller,
 * routes, and React UI are intentionally separate so each phase ships
 * something testable. The /tickets route currently dead-ends at
 * ModuleStubController — that gets replaced once the controller lands.
 *
 * Schema choices:
 *   - user_id: FK to users with nullOnDelete so a closed support history
 *     survives a user account being deleted (otherwise we'd lose context
 *     for past resolutions). Indexed by foreignId() automatically.
 *   - status: enum mirrors the spec values exactly. Indexed because the
 *     primary list view will almost always filter by it.
 *   - No soft-delete column; tickets are typically resolved by status,
 *     not by hiding.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('subject');
            $table->text('message');
            $table->enum('status', ['open', 'closed', 'in_progress'])
                ->default('open')
                ->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};
