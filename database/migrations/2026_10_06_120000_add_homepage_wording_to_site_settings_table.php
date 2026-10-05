<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The About blurb an earlier migration wrote into every settings row. It makes a ranking
     * claim ("#1") nobody has substantiated, so a row still holding exactly this text is
     * cleared and falls back to the neutral default. Text the admin edited is left alone.
     */
    private const OLD_FOOTER_ABOUT = "Bangladesh's #1 gift shop — handcrafted ceramic tea sets, porcelain collections, and premium gift boxes, delivered across Bangladesh with cash-on-delivery and mobile-wallet checkout.";

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('site_settings', function (Blueprint $table) {
            // Homepage hero and newsletter wording. All optional: blank means the storefront's
            // neutral default, so no discount or ranking claim appears unless the admin writes one.
            $table->string('hero_badge', 80)->nullable();
            $table->string('hero_title', 160)->nullable();
            $table->text('hero_text')->nullable();
            $table->string('hero_hot_deals_text', 120)->nullable();
            $table->string('hero_new_arrivals_text', 120)->nullable();
            $table->string('newsletter_headline', 120)->nullable();
            $table->string('newsletter_text', 240)->nullable();
        });

        DB::table('site_settings')
            ->where('footer_about', self::OLD_FOOTER_ABOUT)
            ->update(['footer_about' => null]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('site_settings', function (Blueprint $table) {
            $table->dropColumn([
                'hero_badge',
                'hero_title',
                'hero_text',
                'hero_hot_deals_text',
                'hero_new_arrivals_text',
                'newsletter_headline',
                'newsletter_text',
            ]);
        });
    }
};
