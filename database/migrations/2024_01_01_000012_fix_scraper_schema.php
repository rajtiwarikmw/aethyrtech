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
        // Drop old columns from products table if they exist
        Schema::table('products', function (Blueprint $table) {
            // Drop old star count columns (we only need percentages)
            
            
            // Drop offer_expires_at (renaming to countdown)
            if (Schema::hasColumn('products', 'offer_expires_at')) {
                $table->dropColumn('offer_expires_at');
            }
        });

        // Add new columns to products table
        Schema::table('products', function (Blueprint $table) {
            // Add countdown column (replaces offer_expires_at)

            
            // Add customers_say if not exists
            if (!Schema::hasColumn('products', 'customers_say')) {
                $table->text('customers_say')->nullable()->after('description');
            }
            
            // Keep only percentage columns for star ratings (already exist from previous migration)
            // rating_1_star_percent, rating_2_star_percent, etc.
            
            // Add scraper_id to track scraping instances
           // $table->string('scraper_id', 50)->nullable()->after('scraped_date')->index();
        });

        // Add new columns to reviews table
        Schema::table('reviews', function (Blueprint $table) {
            // Add variant_info column
            
            
            // Drop sentiment column (will use rating-based filter in admin instead)
            if (Schema::hasColumn('reviews', 'sentiment')) {
                $table->dropColumn('sentiment');
            }
            
            // Add scraper_id to track scraping instances
            $table->string('scraper_id', 50)->nullable()->after('platform')->index();
        });

        // Add scraper_id to product_rankings table
        Schema::table('product_rankings', function (Blueprint $table) {
            $table->string('scraper_id', 50)->nullable()->after('platform')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Restore products table
        Schema::table('products', function (Blueprint $table) {
            // Add back count columns
            $table->integer('rating_1_star_count')->default(0);
            $table->integer('rating_2_star_count')->default(0);
            $table->integer('rating_3_star_count')->default(0);
            $table->integer('rating_4_star_count')->default(0);
            $table->integer('rating_5_star_count')->default(0);
            
            // Restore offer_expires_at
            $table->timestamp('offer_expires_at')->nullable();
            
            // Drop new columns
        });

        // Restore reviews table
        Schema::table('reviews', function (Blueprint $table) {
            // Add back sentiment
            $table->enum('sentiment', ['positive', 'neutral', 'critical'])->nullable();
            
            // Drop new columns
            $table->dropColumn(['variant_info', 'scraper_id']);
        });

        // Restore product_rankings table
        Schema::table('product_rankings', function (Blueprint $table) {
            $table->dropColumn('scraper_id');
        });
    }
};
