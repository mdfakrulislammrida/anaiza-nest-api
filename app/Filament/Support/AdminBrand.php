<?php

namespace App\Filament\Support;

use App\Models\SiteSetting;
use App\Support\MediaUrl;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

/**
 * How the admin presents the shop: the name "Anaiza Nest" and the logo the owner keeps in Site settings (the one the storefront uses).
 *
 * The name is set on the panel itself, not taken from APP_NAME: APP_NAME ("Anaiza Nest API") also names the mail sender, the backup files and the cache
 * and session keys, and the server's .env is not ours to edit.
 *
 * Which logo: on a light page the navy brand-kit logo, then the previous logo, then the Monogram, then just the name as text. On a dark page the ivory
 * logo if there is one; otherwise the same picture on a small ivory plate, so the mark is never recoloured or stretched.
 */
final class AdminBrand
{
    public const NAME = 'Anaiza Nest';

    private const REQUEST_KEY = 'admin.brand.settings';

    /** The logo for a light page, as an address (null: the name is shown as text). */
    public static function logo(): ?string
    {
        $setting = self::settings();

        return MediaUrl::resolve(self::first($setting?->logo_navy, $setting?->logo_url, $setting?->monogram));
    }

    /** The logo for a dark page; null when there is no logo at all (the light one then falls back to the name as text). */
    public static function darkLogo(): string|Htmlable|null
    {
        $setting = self::settings();

        if (filled($setting?->logo_ivory)) {
            return MediaUrl::resolve($setting->logo_ivory);
        }

        $light = self::logo();

        if ($light === null) {
            return null;
        }

        // No ivory logo: the usual one on an ivory plate (a flat background behind it, nothing done to the picture).
        return new HtmlString('<img src="'.e($light).'" alt="'.e(self::NAME).'" class="fi-brand-plate">');
    }

    /** The browser tab icon: the Monogram from Site settings, if there is one (otherwise the panel shows none, as before). */
    public static function favicon(): ?string
    {
        return MediaUrl::resolve(self::settings()?->monogram);
    }

    private static function first(?string ...$paths): ?string
    {
        foreach ($paths as $path) {
            if (filled($path)) {
                return $path;
            }
        }

        return null;
    }

    /** Site settings, read once per request (the logo, the dark logo and the favicon all ask). */
    private static function settings(): ?SiteSetting
    {
        $attributes = request()->attributes;

        if (! $attributes->has(self::REQUEST_KEY)) {
            try {
                $attributes->set(self::REQUEST_KEY, SiteSetting::query()->first());
            } catch (\Throwable) {
                // Before the tables exist (a fresh install, a migration run) the admin simply shows the name.
                $attributes->set(self::REQUEST_KEY, null);
            }
        }

        return $attributes->get(self::REQUEST_KEY);
    }
}
