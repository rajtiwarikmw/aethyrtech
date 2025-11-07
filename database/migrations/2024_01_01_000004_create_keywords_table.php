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
        Schema::create('keywords', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('platform', 50); // amazon, flipkart, vijaysales
            $table->string('keyword', 255);
            $table->boolean('status')->default(true); // active/inactive
            $table->timestamps();

            // Indexes
            $table->index('platform', 'keywords_platform_index');
            $table->index('status', 'keywords_status_index');
            $table->unique(['platform', 'keyword'], 'platform_keyword_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('keywords');
    }
};
