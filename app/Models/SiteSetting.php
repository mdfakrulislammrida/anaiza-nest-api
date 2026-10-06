<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SiteSetting extends Model
{
    protected $fillable = [
        'site_name',
        'logo_url',
        'contact_phone',
        'contact_email',
        'address',
        'promo_text',
        'nav_links',
        'footer_about',
        'footer_links',
        'social_links',
        'footer_copyright_text',
        'brand_description',
        'free_delivery_threshold',
        'delivery_fee_dhaka',
        'delivery_fee_outside_dhaka',
        'delivery_days_dhaka_min',
        'delivery_days_dhaka_max',
        'delivery_days_outside_min',
        'delivery_days_outside_max',
        'return_window_days',
        'show_categories_menu',
        'hero_badge',
        'hero_title',
        'hero_text',
        'hero_hot_deals_text',
        'hero_new_arrivals_text',
        'newsletter_headline',
        'newsletter_text',
        'tagline',
        'logo_navy',
        'logo_ivory',
        'monogram',
        'low_stock_threshold',
        'corporate_intro',
        'corporate_notify_email',
    ];

    /**
     * The delivery/return rules as they stood before they became editable.
     * Mirrors the migration's column defaults, but as real in-PHP defaults:
     * Eloquent does not re-read a row after inserting it, so a freshly
     * created or entirely missing settings row would otherwise read these as
     * null -- and checkout would charge the wrong fee.
     */
    protected $attributes = [
        'free_delivery_threshold' => 2000,
        'delivery_fee_dhaka' => 80,
        'delivery_fee_outside_dhaka' => 130,
        'delivery_days_dhaka_min' => 1,
        'delivery_days_dhaka_max' => 3,
        'delivery_days_outside_min' => 3,
        'delivery_days_outside_max' => 5,
        'return_window_days' => 7,
        'site_name' => 'Anaiza Nest',
        'show_categories_menu' => true,
        'tagline' => 'Gifted, beautifully.',
        'low_stock_threshold' => 5,
    ];

    protected function casts(): array
    {
        return [
            'nav_links' => 'array',
            'footer_links' => 'array',
            'social_links' => 'array',
            'free_delivery_threshold' => 'integer',
            'delivery_fee_dhaka' => 'integer',
            'delivery_fee_outside_dhaka' => 'integer',
            'delivery_days_dhaka_min' => 'integer',
            'delivery_days_dhaka_max' => 'integer',
            'delivery_days_outside_min' => 'integer',
            'delivery_days_outside_max' => 'integer',
            'return_window_days' => 'integer',
            'show_categories_menu' => 'boolean',
            'low_stock_threshold' => 'integer',
        ];
    }

    /**
     * Fallbacks below mirror today's hardcoded frontend content -- used
     * wherever a field hasn't been set yet (a fresh install, or a field the
     * admin hasn't touched), so the storefront and the admin form never show
     * up blank.
     */
    /**
     * The sentence the form used to pre-fill and save as a literal, back when the
     * threshold was fixed. A stored copy of exactly this text was never written by
     * an admin, so it is treated as "not customised" and follows the threshold.
     */
    private const LEGACY_DEFAULT_PROMO_TEXT = 'Free delivery inside Dhaka on orders over ৳2,000';

    /** The promo text to show: the admin's own wording, else the generated default. */
    public function promoTextOrDefault(): string
    {
        if (blank($this->promo_text) || $this->promo_text === self::LEGACY_DEFAULT_PROMO_TEXT) {
            return self::defaultPromoText($this);
        }

        return $this->promo_text;
    }

    public static function defaultPromoText(?self $settings = null): string
    {
        $threshold = number_format(($settings ?? new self)->free_delivery_threshold);

        return "Free delivery inside Dhaka on orders over ৳{$threshold}";
    }

    public static function defaultNavLinks(): array
    {
        return [
            ['label' => 'Home', 'url' => '/'],
            ['label' => 'Shop', 'url' => '/shop'],
            ['label' => 'Special prices', 'url' => '/hot-deals'],
            ['label' => 'Gift finder', 'url' => '/gift-finder'],
            ['label' => 'Blog', 'url' => '/blog'],
            ['label' => 'Contact', 'url' => '/contact'],
        ];
    }

    /**
     * The brand kit's one-line boilerplate: the footer blurb and the default meta description.
     */
    public static function defaultFooterAbout(): string
    {
        return 'Anaiza Nest is a Dhaka gifting house for tea sets, gift boxes and homeware, packed by hand and ready to give.';
    }

    /**
     * The brand kit's short boilerplate: the Organization description and llms.txt summary
     * when the admin has not written a brand description.
     */
    public static function defaultBrandDescription(): string
    {
        return 'Anaiza Nest curates ceramic and glass tea sets, gift collections and homeware for people who care how a gift feels to open. Every order is packed by hand and sent gift-ready, online at anaizanest.com and in store in Dhaka. Anaiza Nest is part of Fast-Signs Group, trading in Bangladesh since 2003.';
    }

    public static function defaultFooterLinks(): array
    {
        return [
            ['label' => 'Track order', 'url' => '/track-order'],
            ['label' => 'Shipping policy', 'url' => '/pages/shipping'],
            ['label' => 'Returns & refunds', 'url' => '/pages/returns'],
            ['label' => 'FAQs', 'url' => '/faq'],
            ['label' => 'Contact us', 'url' => '/contact'],
        ];
    }

    public static function defaultCopyrightText(): string
    {
        return 'All rights reserved.';
    }
}
