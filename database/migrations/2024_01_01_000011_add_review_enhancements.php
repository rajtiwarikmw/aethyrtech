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
        // Add sentiment and video_urls to reviews table
        Schema::table('reviews', function (Blueprint $table) {
            $table->string('sentiment', 20)->nullable()->after('verified_purchase')->index();
            $table->text('video_urls')->nullable()->after('review_images');
        });

        // Add customers_say summary and star distribution to products table
        Schema::table('products', function (Blueprint $table) {
            $table->text('customers_say')->nullable()->after('description');
            $table->integer('rating_1_star_count')->default(0)->after('rating');
            $table->decimal('rating_1_star_percent', 5, 2)->default(0)->after('rating_1_star_count');
            $table->integer('rating_2_star_count')->default(0)->after('rating_1_star_percent');
            $table->decimal('rating_2_star_percent', 5, 2)->default(0)->after('rating_2_star_count');
            $table->integer('rating_3_star_count')->default(0)->after('rating_2_star_percent');
            $table->decimal('rating_3_star_percent', 5, 2)->default(0)->after('rating_3_star_count');
            $table->integer('rating_4_star_count')->default(0)->after('rating_3_star_percent');
            $table->decimal('rating_4_star_percent', 5, 2)->default(0)->after('rating_4_star_count');
            $table->integer('rating_5_star_count')->default(0)->after('rating_4_star_percent');
            $table->decimal('rating_5_star_percent', 5, 2)->default(0)->after('rating_5_star_count');
            $table->timestamp('offer_expires_at')->nullable()->after('sale_price');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropColumn(['sentiment', 'video_urls']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn([
                'customers_say',
                'rating_1_star_count',
                'rating_1_star_percent',
                'rating_2_star_count',
                'rating_2_star_percent',
                'rating_3_star_count',
                'rating_3_star_percent',
                'rating_4_star_count',
                'rating_4_star_percent',
                'rating_5_star_count',
                'rating_5_star_percent',
                'offer_expires_at',
            ]);
        });
    }
};
