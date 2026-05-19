<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase O-3 — Districts as the 4th level of the address tree.
 *
 *   country → state (governorate / region) → city → district
 *
 * Mirrors the existing countries / states / cities schema:
 * bilingual names, soft sort_order, and an is_active flag for the UI.
 *
 * No FK from customers / orders yet — the field-level free-text columns
 * (`city`, `governorate`, `country`) keep working. A future migration
 * can add `district_id` once operator workflows depend on it.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('districts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('city_id')->constrained('cities')->cascadeOnDelete();
            $table->string('name_ar', 150);
            $table->string('name_en', 150)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['city_id', 'is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('districts');
    }
};
