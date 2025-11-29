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
        Schema::create('scraper_runs', function (Blueprint $table) {
            $table->id();
            $table->string('scraper_id', 10)->unique()->index(); // 10-digit unique ID
            $table->foreignId('configuration_id')->nullable()->constrained('scraper_configurations')->onDelete('set null');
            $table->string('platform', 50)->index(); // amazon, flipkart, etc.
            $table->string('category', 100)->index(); // Printers, Laptops, etc.
            $table->string('tag', 100)->nullable()->index(); // "20% sale Nov 11"
            $table->text('category_url'); // URL that was scraped
            $table->text('description')->nullable(); // Description at time of run
            
            // Run statistics
            $table->enum('status', ['pending', 'running', 'completed', 'failed', 'stopped'])->default('pending')->index();
            $table->timestamp('started_at')->nullable()->index();
            $table->timestamp('completed_at')->nullable();
            $table->integer('duration_seconds')->nullable(); // How long it took
            
            // Scraping results
            $table->integer('products_scraped')->default(0);
            $table->integer('reviews_scraped')->default(0);
            $table->integer('rankings_scraped')->default(0);
            $table->integer('errors_count')->default(0);
            
            // Additional info
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable(); // Store any additional info
            $table->string('triggered_by', 100)->default('manual'); // manual, schedule, api
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('set null'); // Who triggered it
            
            $table->timestamps();
            
            // Indexes for filtering and comparison
            $table->index(['platform', 'category', 'created_at']);
            $table->index(['platform', 'status']);
            $table->index('created_at');
            $table->index(['category', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scraper_runs');
    }
};
