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
        Schema::create('scraper_configurations', function (Blueprint $table) {
            $table->id();
            $table->string('platform', 50)->index(); // amazon, flipkart, etc.
            $table->string('category', 100)->index(); // Printers, Laptops, etc.
            $table->string('tag', 100)->nullable()->index(); // "20% sale Nov 11", "Black Friday", etc.
            $table->text('category_url'); // URL to scrape
            $table->text('description')->nullable(); // Admin notes
            $table->enum('status', ['active', 'inactive', 'paused'])->default('active')->index();
            $table->integer('total_runs')->default(0); // How many times this config was run
            $table->timestamp('last_run_at')->nullable(); // Last time this config was run
            $table->string('last_scraper_id', 50)->nullable(); // Last generated scraper ID
            $table->timestamps();
            
            // Indexes for filtering
            $table->index(['platform', 'category']);
            $table->index(['platform', 'status']);
            $table->index('last_run_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scraper_configurations');
    }
};
