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
        Schema::create('scraping_urls', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('platform', 50); // amazon, flipkart, vijaysales, etc.
            $table->text('url'); // Product URL to scrape
            $table->string('status', 50)->default('pending'); // pending, processing, completed, failed
            $table->integer('priority')->default(0); // Higher priority scraped first
            $table->timestamp('last_scraped_at')->nullable();
            $table->text('error_message')->nullable();
            $table->integer('retry_count')->default(0);
            $table->timestamps();

            // Indexes
            $table->index('platform', 'scraping_urls_platform_index');
            $table->index('status', 'scraping_urls_status_index');
            $table->index('priority', 'scraping_urls_priority_index');
            $table->index('last_scraped_at', 'scraping_urls_last_scraped_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scraping_urls');
    }
};
