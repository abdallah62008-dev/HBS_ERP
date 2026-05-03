<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Nullable so the column can be added without a default role row, then
            // backfilled by the seeder. RBAC enforcement treats users with null
            // role_id as having no permissions (Super Admin assignment is required).
            $table->foreignId('role_id')
                ->nullable()
                ->after('phone')
                ->constrained('roles')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('role_id');
        });
    }
};
