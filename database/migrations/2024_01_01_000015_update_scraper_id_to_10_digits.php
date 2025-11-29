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
        // Update products table
        Schema::table('products', function (Blueprint $table) {
            $table->string('scraper_id', 10)->nullable()->change();
        });

        // Update reviews table
        Schema::table('reviews', function (Blueprint $table) {
            $table->string('scraper_id', 10)->nullable()->change();
        });

        // Update product_rankings table
        Schema::table('product_rankings', function (Blueprint $table) {
            $table->string('scraper_id', 10)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert to 50 characters
        Schema::table('products', function (Blueprint $table) {
            $table->string('scraper_id', 50)->nullable()->change();
        });

        Schema::table('reviews', function (Blueprint $table) {
            $table->string('scraper_id', 50)->nullable()->change();
        });

        Schema::table('product_rankings', function (Blueprint $table) {
            $table->string('scraper_id', 50)->nullable()->change();
        });
    }
};
