<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The pictures behind the homepage hero panel and its two side tiles. Each picture keeps the path of its largest
     * copy and the list of copies (path, width, height) the browser chooses between; every column is optional, and
     * with none set the homepage looks as it always did.
     */
    public function up(): void
    {
        Schema::table('site_settings', function (Blueprint $table) {
            $table->string('hero_image')->nullable();
            $table->json('hero_image_renditions')->nullable();
            $table->string('hero_image_mobile')->nullable();
            $table->json('hero_image_mobile_renditions')->nullable();
            $table->string('hot_deals_tile_image')->nullable();
            $table->json('hot_deals_tile_image_renditions')->nullable();
            $table->string('new_arrivals_tile_image')->nullable();
            $table->json('new_arrivals_tile_image_renditions')->nullable();
            $table->string('hero_image_alt', 160)->nullable();
            $table->string('hot_deals_tile_image_alt', 160)->nullable();
            $table->string('new_arrivals_tile_image_alt', 160)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('site_settings', function (Blueprint $table) {
            $table->dropColumn([
                'hero_image', 'hero_image_renditions',
                'hero_image_mobile', 'hero_image_mobile_renditions',
                'hot_deals_tile_image', 'hot_deals_tile_image_renditions',
                'new_arrivals_tile_image', 'new_arrivals_tile_image_renditions',
                'hero_image_alt', 'hot_deals_tile_image_alt', 'new_arrivals_tile_image_alt',
            ]);
        });
    }
};
