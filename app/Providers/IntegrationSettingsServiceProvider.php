<?php

namespace App\Providers;

use App\Models\IntegrationSetting;
use App\Models\SiteSetting;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

/**
 * Lets the admin override mail/Socialite credentials from the database
 * instead of only from .env, and have it take effect immediately -- no
 * restart, no `php artisan config:cache` dance.
 *
 * How this actually works: outside Octane/a long-running queue worker, a
 * classic PHP-FPM Laravel request rebuilds the entire service container
 * from scratch every time, and Mail/Socialite's managers are lazy -- they
 * only read config('mail.mailers.smtp...')/config('services.google...') at
 * the moment a mailer or OAuth driver is actually built, not at container
 * boot. Every provider's boot() runs before the router dispatches to a
 * controller, so overriding config() here is guaranteed to be in place
 * before any mail-sending or Socialite code that runs later in the SAME
 * request. Nothing at the actual call sites needs to know this exists.
 *
 * A key is only overridden when the matching DB field is non-empty, so a
 * blank field simply leaves whatever .env already put there untouched --
 * that's the whole fallback mechanism, no extra logic needed for it.
 */
class IntegrationSettingsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        try {
            $settings = IntegrationSetting::query()->first();
        } catch (\Throwable $e) {
            // Table not migrated yet (fresh install) -- .env stays authoritative.
            Log::debug('IntegrationSettingsServiceProvider: settings unavailable, using .env as-is.', [
                'error' => $e->getMessage(),
            ]);

            return;
        }

        if (! $settings) {
            return;
        }

        $this->applyMailOverrides($settings);
        $this->applySocialiteOverrides($settings);
    }

    private function applyMailOverrides(IntegrationSetting $settings): void
    {
        if (filled($settings->mailer)) {
            config(['mail.default' => $settings->mailer]);
        }

        $smtpOverrides = array_filter([
            'mail.mailers.smtp.host' => $settings->smtp_host,
            'mail.mailers.smtp.port' => $settings->smtp_port,
            'mail.mailers.smtp.username' => $settings->smtp_username,
            'mail.mailers.smtp.password' => $settings->smtp_password,
            'mail.from.address' => $settings->smtp_from_address,
        ], fn ($value) => filled($value));

        if ($smtpOverrides !== []) {
            config($smtpOverrides);
        }

        // "Default to the site name if blank" -- resolved once, here, so
        // every mailable reads a single config('mail.from.name') instead of
        // each one re-deriving its own from-name fallback.
        $fromName = $settings->smtp_from_name
            ?: SiteSetting::query()->value('site_name')
            ?: null;

        if (filled($fromName)) {
            config(['mail.from.name' => $fromName]);
        }
    }

    private function applySocialiteOverrides(IntegrationSetting $settings): void
    {
        $overrides = array_filter([
            'services.google.client_id' => $settings->google_client_id,
            'services.google.client_secret' => $settings->google_client_secret,
            'services.facebook.client_id' => $settings->facebook_app_id,
            'services.facebook.client_secret' => $settings->facebook_app_secret,
        ], fn ($value) => filled($value));

        if ($overrides !== []) {
            config($overrides);
        }
    }
}
