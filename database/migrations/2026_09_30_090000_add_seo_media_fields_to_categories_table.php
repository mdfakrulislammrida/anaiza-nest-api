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
        Schema::table('categories', function (Blueprint $table) {
            $table->string('banner_desktop')->nullable()->after('description');
            $table->unsignedInteger('banner_desktop_width')->nullable()->after('banner_desktop');
            $table->unsignedInteger('banner_desktop_height')->nullable()->after('banner_desktop_width');

            $table->string('banner_mobile')->nullable()->after('banner_desktop_height');
            $table->unsignedInteger('banner_mobile_width')->nullable()->after('banner_mobile');
            $table->unsignedInteger('banner_mobile_height')->nullable()->after('banner_mobile_width');
            // True when banner_mobile was generated from banner_desktop rather
            // than uploaded directly -- lets the observer know it's safe (and
            // expected) to regenerate it the next time banner_desktop changes.
            $table->boolean('banner_mobile_auto_generated')->default(false)->after('banner_mobile_height');

            $table->string('thumbnail')->nullable()->after('banner_mobile_auto_generated');
            $table->unsignedInteger('thumbnail_width')->nullable()->after('thumbnail');
            $table->unsignedInteger('thumbnail_height')->nullable()->after('thumbnail_width');

            $table->string('intro_text', 300)->nullable()->after('thumbnail_height');
            $table->longText('seo_description')->nullable()->after('intro_text');
            $table->string('meta_title')->nullable()->after('seo_description');
            $table->string('meta_description')->nullable()->after('meta_title');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn([
                'banner_desktop', 'banner_desktop_width', 'banner_desktop_height',
                'banner_mobile', 'banner_mobile_width', 'banner_mobile_height', 'banner_mobile_auto_generated',
                'thumbnail', 'thumbnail_width', 'thumbnail_height',
                'intro_text', 'seo_description', 'meta_title', 'meta_description',
            ]);
        });
    }
};
