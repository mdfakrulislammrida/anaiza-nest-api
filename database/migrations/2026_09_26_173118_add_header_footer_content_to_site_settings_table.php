<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('site_settings', function (Blueprint $table) {
            $table->string('promo_text')->nullable()->after('site_name');
            $table->json('nav_links')->nullable()->after('promo_text');
            $table->text('footer_about')->nullable()->after('tiktok_url');
            $table->json('footer_links')->nullable()->after('footer_about');
            $table->json('social_links')->nullable()->after('footer_links');
            $table->string('footer_copyright_text')->nullable()->after('social_links');
        });

        // One-off backfill so the existing settings row (and the admin form)
        // start out populated with today's hardcoded frontend content
        // instead of blank fields, and any facebook/instagram/youtube/tiktok
        // URL already on file carries over into the new social_links list.
        DB::table('site_settings')->get()->each(function ($row) {
            $socialLinks = collect([
                'facebook' => $row->facebook_url,
                'instagram' => $row->instagram_url,
                'youtube' => $row->youtube_url,
                'tiktok' => $row->tiktok_url,
            ])
                ->filter()
                ->map(fn ($url, $platform) => ['platform' => $platform, 'url' => $url])
                ->values()
                ->all();

            DB::table('site_settings')->where('id', $row->id)->update([
                'promo_text' => 'Free delivery inside Dhaka on orders over ৳2,000',
                'nav_links' => json_encode([
                    ['label' => 'Home', 'url' => '/'],
                    ['label' => 'Shop', 'url' => '/shop'],
                    ['label' => 'Hot Deals', 'url' => '/hot-deals'],
                    ['label' => 'Gift Finder', 'url' => '/gift-finder'],
                    ['label' => 'Contact', 'url' => '/contact'],
                ]),
                'footer_about' => "Bangladesh's #1 gift shop — handcrafted ceramic tea sets, porcelain collections, and premium gift boxes, delivered across Bangladesh with cash-on-delivery and mobile-wallet checkout.",
                'footer_links' => json_encode([
                    ['label' => 'Track Order', 'url' => '/track-order'],
                    ['label' => 'Shipping Policy', 'url' => '/pages/shipping'],
                    ['label' => 'Returns & Refunds', 'url' => '/pages/returns'],
                    ['label' => 'FAQs', 'url' => '/faq'],
                    ['label' => 'Contact Us', 'url' => '/contact'],
                ]),
                'social_links' => json_encode($socialLinks),
                'footer_copyright_text' => 'All rights reserved.',
            ]);
        });

        Schema::table('site_settings', function (Blueprint $table) {
            $table->dropColumn(['facebook_url', 'instagram_url', 'youtube_url', 'tiktok_url']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('site_settings', function (Blueprint $table) {
            $table->string('facebook_url')->nullable();
            $table->string('instagram_url')->nullable();
            $table->string('youtube_url')->nullable();
            $table->string('tiktok_url')->nullable();
        });

        DB::table('site_settings')->get()->each(function ($row) {
            $socialLinks = collect(json_decode($row->social_links ?? '[]', true))
                ->keyBy('platform')
                ->map(fn ($link) => $link['url']);

            DB::table('site_settings')->where('id', $row->id)->update([
                'facebook_url' => $socialLinks->get('facebook'),
                'instagram_url' => $socialLinks->get('instagram'),
                'youtube_url' => $socialLinks->get('youtube'),
                'tiktok_url' => $socialLinks->get('tiktok'),
            ]);
        });

        Schema::table('site_settings', function (Blueprint $table) {
            $table->dropColumn([
                'promo_text',
                'nav_links',
                'footer_about',
                'footer_links',
                'social_links',
                'footer_copyright_text',
            ]);
        });
    }
};
