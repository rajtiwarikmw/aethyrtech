<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->string('platform', 50)->after('product_id')->nullable()->index();
        });

        // Update existing reviews with platform from products table
        DB::statement('
            UPDATE reviews r
            INNER JOIN products p ON r.product_id = p.id
            SET r.platform = p.platform
        ');

        // Make platform not nullable after updating existing records
        Schema::table('reviews', function (Blueprint $table) {
            $table->string('platform', 50)->nullable(false)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropColumn('platform');
        });
    }
};
