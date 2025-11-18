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
        // Add scraper schedule tracking fields
        Schema::table('scraping_urls', function (Blueprint $table) {
            $table->timestamp('last_run_time')->nullable()->after('status');
            $table->timestamp('next_scheduled_run')->nullable()->after('last_run_time');
            $table->integer('run_count')->default(0)->after('next_scheduled_run');
        });

        // Create scraper_runs table to track manual scraper executions
        Schema::create('scraper_runs', function (Blueprint $table) {
            $table->id();
            $table->string('platform', 50)->index();
            $table->enum('type', ['manual', 'scheduled'])->default('manual');
            $table->enum('status', ['pending', 'running', 'completed', 'failed'])->default('pending')->index();
            $table->integer('products_scraped')->default(0);
            $table->integer('products_added')->default(0);
            $table->integer('products_updated')->default(0);
            $table->integer('errors_count')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->integer('duration_seconds')->nullable();
            $table->unsignedBigInteger('triggered_by')->nullable(); // user_id
            $table->timestamps();
            
            $table->index(['platform', 'status']);
            $table->index(['type', 'status']);
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('scraping_urls', function (Blueprint $table) {
            $table->dropColumn(['last_run_time', 'next_scheduled_run', 'run_count']);
        });
        
        Schema::dropIfExists('scraper_runs');
    }
};
