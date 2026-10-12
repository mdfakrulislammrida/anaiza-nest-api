<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Campaign pages built from the admin and shown at /lp/<slug> on the storefront.
        Schema::create('landing_pages', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            // draft | published. A published page is only live between publish_from and publish_until (both optional).
            $table->string('status', 12)->default('draft');
            $table->dateTime('publish_from')->nullable();
            $table->dateTime('publish_until')->nullable();
            // focus: logo only, no menu or footer. normal: the site's header and footer.
            $table->string('layout', 10)->default('focus');
            $table->string('seo_title', 70)->nullable();
            $table->string('meta_description', 255)->nullable();
            $table->string('og_image')->nullable();
            // Ad destinations should not be found by search engines unless someone chooses it.
            $table->boolean('noindex')->default(true);
            $table->string('canonical')->nullable();
            // The ordered blocks, as saved from the admin (HTML blocks keep what was typed AND the cleaned copy that is served).
            $table->json('blocks')->nullable();
            // What the last save removed from custom HTML, shown to the author.
            $table->json('notes')->nullable();
            $table->timestamps();
        });

        // One row per page per day. Views are counted once per visitor session, bots excluded.
        Schema::create('landing_page_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('landing_page_id')->constrained()->cascadeOnDelete();
            $table->date('day');
            $table->unsignedInteger('views')->default(0);
            $table->unsignedInteger('add_to_carts')->default(0);

            $table->unique(['landing_page_id', 'day']);
        });

        Schema::table('orders', function (Blueprint $table) {
            // The landing page an order came from. The slug is kept as well, so the order still says where it came from
            // after the page is deleted.
            $table->foreignId('landing_page_id')->nullable()->constrained()->nullOnDelete();
            $table->string('landing_slug', 160)->nullable()->index();
            // Ad click ids, captured with the UTM parameters and passed to the server-side conversion events.
            $table->string('fbclid')->nullable();
            $table->string('gclid')->nullable();
            $table->string('ttclid')->nullable();
        });

        Schema::table('users', function (Blueprint $table) {
            // Whether this person may allow scripts in a landing page's custom HTML. Off for everyone; only a super-admin sets it.
            $table->boolean('landing_scripts_allowed')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('landing_scripts_allowed');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('landing_page_id');
            $table->dropIndex(['landing_slug']);
            $table->dropColumn(['landing_slug', 'fbclid', 'gclid', 'ttclid']);
        });

        Schema::dropIfExists('landing_page_stats');
        Schema::dropIfExists('landing_pages');
    }
};
