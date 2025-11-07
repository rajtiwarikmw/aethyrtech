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
        Schema::create('product_rankings', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('product_id')->nullable(); // Can be null if product not in our DB yet
            $table->string('sku', 255); // Product SKU/ASIN
            $table->unsignedBigInteger('keyword_id');
            $table->integer('position'); // Position in search results (1-based)
            $table->integer('page'); // Page number where product was found (1-5)
            $table->timestamps();

            // Foreign key constraints
            $table->foreign('product_id')
                  ->references('id')
                  ->on('products')
                  ->onDelete('cascade');

            $table->foreign('keyword_id')
                  ->references('id')
                  ->on('keywords')
                  ->onDelete('cascade');

            // Indexes
            $table->index('product_id', 'rankings_product_id_index');
            $table->index('keyword_id', 'rankings_keyword_id_index');
            $table->index('sku', 'rankings_sku_index');
            $table->index('position', 'rankings_position_index');
            $table->index('page', 'rankings_page_index');
            $table->index('created_at', 'rankings_created_at_index');
            
            // Composite index for common queries
            $table->index(['keyword_id', 'created_at'], 'rankings_keyword_date_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_rankings');
    }
};
