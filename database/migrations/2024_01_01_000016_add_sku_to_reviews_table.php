<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->string('sku', 100)->nullable()->after('product_id')->index();
        });

        // Populate SKU from products table for existing reviews
        DB::statement('
            UPDATE reviews r
            INNER JOIN products p ON r.product_id = p.id
            SET r.sku = p.sku
            WHERE r.sku IS NULL
        ');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropColumn('sku');
        });
    }
};
