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
            'promo_text' => $this->promo_text ?: SiteSetting::defaultPromoText(),
            'nav_links' => $this->nav_links ?: SiteSetting::defaultNavLinks(),
            'footer_about' => $this->footer_about ?: SiteSetting::defaultFooterAbout(),
            'footer_links' => $this->footer_links ?: SiteSetting::defaultFooterLinks(),
            'social_links' => $this->social_links ?? [],
            'footer_copyright_text' => $this->footer_copyright_text ?: SiteSetting::defaultCopyrightText(),
        ];
    }
}
