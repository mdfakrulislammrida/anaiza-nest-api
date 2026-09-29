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
        Schema::table('product_images', function (Blueprint $table) {
            // `url` itself becomes the WebP-converted, capped-at-1200px-wide
            // version once ProductImageObserver processes an upload -- every
            // existing consumer of `url`/`display_url` gets the optimized
            // image for free. These two are the smaller companions for
            // <img srcset>; nullable because a source image narrower than
            // a given breakpoint has nothing to generate there.
            $table->unsignedInteger('width')->nullable()->after('url');
            $table->unsignedInteger('height')->nullable()->after('width');
            $table->string('url_400')->nullable()->after('height');
            $table->string('url_800')->nullable()->after('url_400');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_images', function (Blueprint $table) {
            $table->dropColumn(['width', 'height', 'url_400', 'url_800']);
        });
    }
};
