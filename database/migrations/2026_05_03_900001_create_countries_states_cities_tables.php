<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 — Egypt & Saudi Location Readiness.
 *
 * Adds three additive lookup tables. The existing free-text columns on
 * `customers` and `orders` (city, governorate, country) are intentionally
 * left untouched — old records keep working, and the new dropdowns simply
 * write the selected Arabic labels into those same columns.
 *
 * No FK columns are added to customers/orders in this phase; the lookup
 * tables exist only to populate cascading dropdowns. A future phase can
 * promote these to FKs once historic data is normalised.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('countries', function (Blueprint $table) {
            $table->id();
            $table->string('code', 8)->unique();   // EG, SA
            $table->string('name_ar', 100);
            $table->string('name_en', 100);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });

        Schema::create('states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('country_id')->constrained('countries')->cascadeOnDelete();
            $table->string('name_ar', 120);
            $table->string('name_en', 120)->nullable();
            $table->string('type', 32)->nullable(); // governorate / region
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['country_id', 'is_active', 'sort_order']);
        });

        Schema::create('cities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('state_id')->constrained('states')->cascadeOnDelete();
            $table->string('name_ar', 120);
            $table->string('name_en', 120)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['state_id', 'is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cities');
        Schema::dropIfExists('states');
        Schema::dropIfExists('countries');
    }
};
