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
        Schema::create('reviews', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('product_id');
            $table->string('review_id', 100)->nullable(); // Amazon review ID
            $table->string('reviewer_name', 255)->nullable();
            $table->string('reviewer_profile_url', 500)->nullable();
            $table->decimal('rating', 3, 2)->nullable(); // 1.00 to 5.00
            $table->string('review_title', 500)->nullable();
            $table->text('review_text')->nullable();
            $table->date('review_date')->nullable();
            $table->boolean('verified_purchase')->default(false);
            $table->integer('helpful_count')->default(0); // Number of people who found this helpful
            $table->json('review_images')->nullable(); // Array of image URLs
            $table->string('variant_info', 500)->nullable(); // e.g., "Color: Black, Size: 256GB"
            $table->string('review_url', 500)->nullable(); // Direct link to the review
            $table->timestamps();

            // Foreign key constraint
            $table->foreign('product_id')
                  ->references('id')
                  ->on('products')
                  ->onDelete('cascade');

            // Indexes
            $table->index('product_id', 'reviews_product_id_index');
            $table->index('rating', 'reviews_rating_index');
            $table->index('review_date', 'reviews_review_date_index');
            $table->index('verified_purchase', 'reviews_verified_purchase_index');
            $table->unique(['product_id', 'review_id'], 'product_review_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};
