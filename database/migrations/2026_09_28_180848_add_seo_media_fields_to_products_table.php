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
        Schema::table('products', function (Blueprint $table) {
            $table->string('short_description', 200)->nullable()->after('description');
            $table->string('video_url')->nullable()->after('og_image');
            $table->string('video_file')->nullable()->after('video_url');
            $table->string('video_poster')->nullable()->after('video_file');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['short_description', 'video_url', 'video_file', 'video_poster']);
        });
    }
};
