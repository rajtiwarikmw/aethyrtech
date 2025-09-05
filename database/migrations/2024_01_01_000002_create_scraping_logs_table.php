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
        Schema::create('scraping_logs', function (Blueprint $table) {
            $table->id();
            $table->string('platform', 50)->index();
            $table->enum('status', ['started', 'completed', 'failed', 'partial'])->index();
            $table->integer('products_found')->default(0);
            $table->integer('products_updated')->default(0);
            $table->integer('products_added')->default(0);
            $table->integer('products_deactivated')->default(0);
            $table->integer('errors_count')->default(0);
            $table->text('error_message')->nullable();
            $table->json('error_details')->nullable(); // JSON for detailed error info
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->integer('duration_seconds')->nullable(); // Duration in seconds
            $table->text('summary')->nullable(); // Summary of scraping session
            $table->timestamps();
            
            // Indexes for performance
            $table->index(['platform', 'status']);
            $table->index(['started_at']);
            $table->index(['completed_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scraping_logs');
    }
};

