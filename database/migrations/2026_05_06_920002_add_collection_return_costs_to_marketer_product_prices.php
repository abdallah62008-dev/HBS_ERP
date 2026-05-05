<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('marketer_product_prices', function (Blueprint $table) {
            if (! Schema::hasColumn('marketer_product_prices', 'collection_cost')) {
                $table->decimal('collection_cost', 12, 2)->nullable()->after('vat_percent');
            }
            if (! Schema::hasColumn('marketer_product_prices', 'return_cost')) {
                $table->decimal('return_cost', 12, 2)->nullable()->after('collection_cost');
            }
        });
    }

    public function down(): void
    {
        Schema::table('marketer_product_prices', function (Blueprint $table) {
            if (Schema::hasColumn('marketer_product_prices', 'return_cost')) {
                $table->dropColumn('return_cost');
            }
            if (Schema::hasColumn('marketer_product_prices', 'collection_cost')) {
                $table->dropColumn('collection_cost');
            }
        });
    }
};
