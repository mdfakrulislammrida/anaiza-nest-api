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
    ];

    protected function casts(): array
    {
        return [
            'nav_links' => 'array',
            'footer_links' => 'array',
            'social_links' => 'array',
        ];
    }

    /**
     * Fallbacks below mirror today's hardcoded frontend content -- used
     * wherever a field hasn't been set yet (a fresh install, or a field the
     * admin hasn't touched), so the storefront and the admin form never show
     * up blank.
     */
    public static function defaultPromoText(): string
    {
        return 'Free delivery inside Dhaka on orders over ৳2,000';
    }

    public static function defaultNavLinks(): array
    {
        return [
            ['label' => 'Home', 'url' => '/'],
            ['label' => 'Shop', 'url' => '/shop'],
            ['label' => 'Hot Deals', 'url' => '/hot-deals'],
            ['label' => 'Gift Finder', 'url' => '/gift-finder'],
            ['label' => 'Contact', 'url' => '/contact'],
        ];
    }

    public static function defaultFooterAbout(): string
    {
        return "Bangladesh's #1 gift shop — handcrafted ceramic tea sets, porcelain collections, and premium gift boxes, delivered across Bangladesh with cash-on-delivery and mobile-wallet checkout.";
    }

    public static function defaultFooterLinks(): array
    {
        return [
            ['label' => 'Track Order', 'url' => '/track-order'],
            ['label' => 'Shipping Policy', 'url' => '/pages/shipping'],
            ['label' => 'Returns & Refunds', 'url' => '/pages/returns'],
            ['label' => 'FAQs', 'url' => '/faq'],
            ['label' => 'Contact Us', 'url' => '/contact'],
        ];
    }

    public static function defaultCopyrightText(): string
    {
        return 'All rights reserved.';
    }
}
