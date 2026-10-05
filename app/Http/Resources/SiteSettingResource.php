<?php

namespace App\Http\Resources;

use App\Models\PaymentSetting;
use App\Models\SiteSetting;
use App\Support\MediaUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SiteSettingResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'site_name' => $this->site_name,
            'logo_url' => MediaUrl::resolve($this->logo_url),
            // Brand kit logo masters; each is null until uploaded, and the storefront falls back
            // to logo_url (then to the site name) when one is missing.
            'logo_navy' => MediaUrl::resolve($this->logo_navy),
            'logo_ivory' => MediaUrl::resolve($this->logo_ivory),
            'monogram' => MediaUrl::resolve($this->monogram),
            'tagline' => $this->tagline ?: null,
            'contact_phone' => $this->contact_phone,
            'contact_email' => $this->contact_email,
            'address' => $this->address,
            'promo_text' => $this->resource->promoTextOrDefault(),
            'show_categories_menu' => (bool) $this->show_categories_menu,
            'nav_links' => $this->nav_links ?: SiteSetting::defaultNavLinks(),
            'footer_about' => $this->footer_about ?: SiteSetting::defaultFooterAbout(),
            'footer_links' => $this->footer_links ?: SiteSetting::defaultFooterLinks(),
            'social_links' => $this->social_links ?? [],
            'footer_copyright_text' => $this->footer_copyright_text ?: SiteSetting::defaultCopyrightText(),
            // The admin's text, or the brand kit's short boilerplate when blank.
            'brand_description' => $this->brand_description ?: SiteSetting::defaultBrandDescription(),
            'low_stock_threshold' => (int) $this->low_stock_threshold,
            // Whether the storefront may say "cash on delivery" (the Payment Settings toggle).
            'cod_enabled' => (bool) (PaymentSetting::query()->value('cod_enabled') ?? true),
            // Homepage wording. Null when blank: the storefront then uses its own neutral default,
            // so no discount or ranking claim shows unless the admin writes one here.
            'homepage' => [
                'hero_badge' => $this->hero_badge ?: null,
                'hero_title' => $this->hero_title ?: null,
                'hero_text' => $this->hero_text ?: null,
                'hot_deals_tile_text' => $this->hero_hot_deals_text ?: null,
                'new_arrivals_tile_text' => $this->hero_new_arrivals_text ?: null,
                'newsletter_headline' => $this->newsletter_headline ?: null,
                'newsletter_text' => $this->newsletter_text ?: null,
            ],
            'policy' => [
                'free_delivery_threshold' => $this->free_delivery_threshold,
                'delivery_fee_dhaka' => $this->delivery_fee_dhaka,
                'delivery_fee_outside_dhaka' => $this->delivery_fee_outside_dhaka,
                'delivery_days_dhaka' => [
                    'min' => $this->delivery_days_dhaka_min,
                    'max' => $this->delivery_days_dhaka_max,
                ],
                'delivery_days_outside_dhaka' => [
                    'min' => $this->delivery_days_outside_min,
                    'max' => $this->delivery_days_outside_max,
                ],
                'return_window_days' => $this->return_window_days,
            ],
        ];
    }
}
