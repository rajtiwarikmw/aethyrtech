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
        Schema::table('product_rankings', function (Blueprint $table) {
            $table->string('platform', 50)->after('keyword_id')->nullable()->index();
        });

        // Update existing rankings with platform from keywords table
        DB::statement('
            UPDATE product_rankings pr
            INNER JOIN keywords k ON pr.keyword_id = k.id
            SET pr.platform = k.platform
        ');

        // Make platform not nullable after updating existing records
        Schema::table('product_rankings', function (Blueprint $table) {
            $table->string('platform', 50)->nullable(false)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_rankings', function (Blueprint $table) {
            $table->dropColumn('platform');
        });
    }
};
