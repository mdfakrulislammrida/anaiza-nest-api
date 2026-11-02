<?php

namespace App\Http\Resources;

use App\Models\IntegrationSetting;
use App\Models\PaymentSetting;
use App\Models\SiteSetting;
use App\Support\DhakaCity;
use App\Support\HomepagePictures;
use App\Support\ImageSize;
use App\Support\Seo;
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
            'logo_url' => Seo::absolute($this->logo_url),
            // Brand kit logo masters, as full https addresses; each is null until uploaded, and the storefront falls back
            // to logo_url (then to the site name) when one is missing.
            'logo_navy' => Seo::absolute($this->logo_navy),
            'logo_ivory' => Seo::absolute($this->logo_ivory),
            'logo_mono' => Seo::absolute($this->logo_mono),
            'monogram' => Seo::absolute($this->monogram),
            'default_share_image' => Seo::absolute($this->default_share_image),
            // The same pictures with their size in pixels (null when it cannot be read), so a page can reserve the space.
            'brand' => [
                'logo_navy' => self::picture($this->logo_navy),
                'logo_ivory' => self::picture($this->logo_ivory),
                'logo_mono' => self::picture($this->logo_mono),
                'monogram' => self::picture($this->monogram),
                'default_share_image' => self::picture($this->default_share_image),
            ],
            'tagline' => $this->tagline ?: null,
            'contact_phone' => $this->contact_phone,
            'contact_email' => $this->contact_email,
            'address' => $this->address,
            'promo_text' => $this->resource->promoTextOrDefault(),
            'show_categories_menu' => (bool) $this->show_categories_menu,
            'nav_auto_categories' => (bool) $this->nav_auto_categories,
            // Menus with ids already turned into today's URLs (a renamed slug never breaks them). Header items
            // may carry one level of children.
            'nav_links' => $this->resource->resolvedNavLinks(),
            'footer_about' => $this->footer_about ?: SiteSetting::defaultFooterAbout(),
            // Up to three titled columns; footer_links is the same links flattened, for older storefront builds.
            'footer_columns' => $this->resource->resolvedFooterColumns(),
            'footer_links' => collect($this->resource->resolvedFooterColumns())->pluck('items')->flatten(1)->values()->all(),
            'social_links' => $this->social_links ?? [],
            'footer_copyright_text' => $this->footer_copyright_text ?: SiteSetting::defaultCopyrightText(),
            // The admin's text, or the brand kit's short boilerplate when blank.
            'brand_description' => $this->brand_description ?: SiteSetting::defaultBrandDescription(),
            'low_stock_threshold' => (int) $this->low_stock_threshold,
            // Which "continue with" providers are on (switched on in Integrations, with their credentials filled in). Only
            // the on/off state: the credentials never leave the server.
            'social_login' => IntegrationSetting::socialLogin(),
            // The WhatsApp bubble and every chat link: the number (the contact phone when none is set), the first message,
            // whether the bubble shows, and the pages that hide it.
            'whatsapp' => [
                'number' => $this->resource->whatsappNumber(),
                'message' => $this->resource->whatsappMessage(),
                'show_bubble' => (bool) ($this->whatsapp_show_bubble ?? true),
                'hidden_paths' => $this->resource->whatsappHiddenPaths(),
            ],
            // The intro on /corporate-gifting; null means the storefront's own default sentence.
            'corporate_intro' => filled($this->corporate_intro) ? trim($this->corporate_intro) : null,
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
                // The pictures behind the hero panel and its two tiles (absolute URLs, a srcset, the size, and the alt text:
                // empty alt means decoration). Every picture is null until one is uploaded.
                'images' => HomepagePictures::payload($this->resource),
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
                // "Inside Dhaka" means Dhaka city only: the ৳80 fee, the 1-3 days and free delivery over the threshold all
                // apply to an address in this division + district + one of these thanas. Anything else is outside.
                'dhaka_city' => [
                    'division' => config('delivery.dhaka_city.division'),
                    'district' => config('delivery.dhaka_city.district'),
                    'thanas' => DhakaCity::thanas(),
                    'outside_upazilas' => DhakaCity::outsideUpazilas(),
                ],
            ],
        ];
    }

    /**
     * @return array{url: ?string, width: ?int, height: ?int}
     */
    private static function picture(?string $stored): array
    {
        $url = Seo::absolute($stored);

        return ['url' => $url] + ($url === null ? ['width' => null, 'height' => null] : ImageSize::of($stored));
    }
}
