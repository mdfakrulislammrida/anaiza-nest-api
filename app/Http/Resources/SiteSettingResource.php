<?php

namespace App\Http\Resources;

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
            // Null when blank on purpose: each consumer falls back to what it used before.
            'brand_description' => $this->brand_description ?: null,
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
