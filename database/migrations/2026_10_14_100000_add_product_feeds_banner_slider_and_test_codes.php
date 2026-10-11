<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            // For the product feeds. Both are optional: the product type falls back to the category name.
            $table->string('google_product_category')->nullable();
            $table->string('product_type')->nullable();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->boolean('exclude_from_feeds')->default(false);
        });

        Schema::table('site_settings', function (Blueprint $table) {
            // Where the feeds say the products live; blank means FRONTEND_URL. One edit at the cutover to the real domain.
            $table->string('feed_storefront_url')->nullable();
            // The unguessable token every feed URL carries.
            $table->string('feed_token', 64)->nullable();
            $table->boolean('feeds_include_out_of_stock')->default(true);
            // The homepage banner slider.
            $table->boolean('slider_autoplay')->default(false);
            $table->unsignedSmallInteger('slider_interval')->default(7);
        });

        Schema::table('banners', function (Blueprint $table) {
            $table->string('button_text', 60)->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            // The desktop image once it has been through the WebP pipeline, and its smaller sizes.
            $table->unsignedSmallInteger('image_width')->nullable();
            $table->unsignedSmallInteger('image_height')->nullable();
            $table->string('image_1280')->nullable();
            $table->string('image_640')->nullable();
            $table->string('mobile_image')->nullable();
            $table->unsignedSmallInteger('mobile_width')->nullable();
            $table->unsignedSmallInteger('mobile_height')->nullable();
            $table->boolean('mobile_auto_generated')->default(false);
        });

        Schema::table('marketing_settings', function (Blueprint $table) {
            // Optional, for checking events in each platform's Test events screen. Not secret, but meant to be temporary.
            $table->string('meta_test_event_code', 60)->nullable();
            $table->string('tiktok_test_event_code', 60)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('marketing_settings', function (Blueprint $table) {
            $table->dropColumn(['meta_test_event_code', 'tiktok_test_event_code']);
        });

        Schema::table('banners', function (Blueprint $table) {
            $table->dropColumn(['button_text', 'starts_at', 'ends_at', 'image_width', 'image_height', 'image_1280', 'image_640', 'mobile_image', 'mobile_width', 'mobile_height', 'mobile_auto_generated']);
        });

        Schema::table('site_settings', function (Blueprint $table) {
            $table->dropColumn(['feed_storefront_url', 'feed_token', 'feeds_include_out_of_stock', 'slider_autoplay', 'slider_interval']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('exclude_from_feeds');
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn(['google_product_category', 'product_type']);
        });
    }
};
