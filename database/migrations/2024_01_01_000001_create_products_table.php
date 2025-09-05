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
        Schema::create('products', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('platform', 50);
            $table->string('sku', 255);
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->string('currency_code', 25)->nullable();
            $table->decimal('price', 10, 2)->nullable();
            $table->decimal('sale_price', 10, 2)->nullable();
            $table->string('seller_name', 255)->nullable();
            $table->string('product_badge', 50)->nullable();
            $table->boolean('amazon_choice')->default(false);
            $table->boolean('bestseller')->default(false);
            $table->text('offers')->nullable();
            $table->json('detailed_offers')->nullable();
            $table->text('cashback_offers')->nullable();
            $table->text('emi_offers')->nullable();
            $table->text('bank_offers')->nullable();
            $table->text('partner_offers')->nullable();
            $table->text('category')->nullable();
            $table->string('inventory_status', 225)->nullable();
            $table->decimal('rating', 3, 2)->nullable();
            $table->integer('review_count')->default(0);
            $table->json('variants')->nullable();
            $table->string('brand', 100)->nullable();
            $table->string('manufacturer', 255)->nullable();
            $table->string('model_name', 255)->nullable();
            $table->string('color', 50)->nullable();
            $table->json('technical_details')->nullable();
            $table->string('weight', 255)->nullable();
            $table->string('dimensions', 255)->nullable();
            $table->boolean('is_prime')->default(false);
            $table->boolean('is_sponsored')->default(false);
            $table->longText('additional_information')->nullable();
            $table->json('image_urls')->nullable();
            $table->json('video_urls')->nullable();
            $table->longText('product_url')->nullable();
            $table->boolean('is_active')->default(true);
            $table->date('scraped_date')->nullable();
            $table->timestamps();

            // Indexes
            $table->unique(['platform', 'sku'], 'platform_sku_unique');
            $table->index(['platform', 'is_active'], 'products_platform_is_active_index');
            $table->index('scraped_date', 'products_scraped_date_index');
            $table->index('sku', 'products_sku_index');
            $table->index('platform', 'products_platform_index');
            $table->index(['brand', 'is_active'], 'products_brand_is_active_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
