<?php

namespace App\Support\System;

use App\Models\IntegrationSetting;
use App\Models\MarketingSetting;
use App\Models\Page;
use App\Models\PaymentSetting;
use App\Models\Product;
use App\Support\Backup\BackupService;
use App\Support\WalletPayments;
use Illuminate\Support\Carbon;

/**
 * The questions to answer before the shop opens, each with PASS, WARN or FAIL. It reads and reports; it sends nothing, changes
 * nothing, and prints no secret: addresses and counts are shown as they are, identifiers and phone numbers masked, and a password,
 * token or key only as "set" or "missing".
 */
final class GoLiveCheck
{
    public const PASS = 'PASS';

    public const WARN = 'WARN';

    public const FAIL = 'FAIL';

    /** The pages a shop needs to open, each with the slugs it may be saved under. */
    public const PAGES = [
        'About' => ['about', 'about-us'],
        'Delivery / shipping' => ['delivery', 'shipping', 'delivery-policy', 'shipping-policy', 'delivery-and-shipping'],
        'Returns' => ['returns', 'return-policy', 'returns-and-refunds', 'refund-policy'],
        'Terms' => ['terms', 'terms-and-conditions', 'terms-of-service'],
        'Privacy' => ['privacy', 'privacy-policy'],
    ];

    /**
     * @return list<array{status: string, label: string, detail: string}>
     */
    public static function run(string $apiUrl = 'https://api.anaizanest.com', string $siteUrl = 'https://anaizanest.com'): array
    {
        $apiUrl = rtrim($apiUrl, '/');
        $siteUrl = rtrim($siteUrl, '/');

        return array_values(array_filter([
            ...self::environment(),
            self::appUrl($apiUrl),
            self::frontendUrl($siteUrl),
            self::cors($siteUrl),
            self::sanctum(),
            ...self::mail(),
            self::scheduler(),
            self::storageLink(),
            ...self::wallets(),
            ...self::products(),
            ...self::pages(),
            ...self::marketing(),
            ...self::socialLogin(),
            self::backup(),
        ]));
    }

    /** @param  list<array{status: string, label: string, detail: string}>  $rows */
    public static function worst(array $rows): string
    {
        $statuses = array_column($rows, 'status');

        return in_array(self::FAIL, $statuses, true) ? self::FAIL : (in_array(self::WARN, $statuses, true) ? self::WARN : self::PASS);
    }

    /** "1234567890" as "12…890": enough to recognise, not enough to use. */
    public static function mask(?string $value): string
    {
        $value = trim((string) $value);

        return match (true) {
            $value === '' => 'missing',
            strlen($value) <= 6 => str_repeat('*', strlen($value)),
            default => substr($value, 0, 2).'...'.substr($value, -3),
        };
    }

    private static function row(string $status, string $label, string $detail): array
    {
        return compact('status', 'label', 'detail');
    }

    /** @return list<array{status: string, label: string, detail: string}> */
    private static function environment(): array
    {
        $env = (string) config('app.env');

        return [
            self::row($env === 'production' ? self::PASS : self::FAIL, 'APP_ENV', $env === 'production' ? 'production' : "is \"{$env}\", it must be production"),
            self::row(config('app.debug') ? self::FAIL : self::PASS, 'APP_DEBUG', config('app.debug') ? 'is true: error pages would show code and settings. Set APP_DEBUG=false.' : 'false'),
        ];
    }

    private static function appUrl(string $expected): array
    {
        $url = rtrim((string) config('app.url'), '/');

        return match (true) {
            $url === $expected => self::row(self::PASS, 'APP_URL', $url),
            str_starts_with($url, 'https://') && ! preg_match('#^https://(localhost|127\.)#', $url) => self::row(self::WARN, 'APP_URL', "{$url} (expected {$expected})"),
            default => self::row(self::FAIL, 'APP_URL', "{$url} is not the https API address (expected {$expected}). Login callbacks and links in emails are built from it."),
        };
    }

    private static function frontendUrl(string $expected): array
    {
        $url = rtrim((string) config('app.frontend_url'), '/');

        return match (true) {
            $url === $expected => self::row(self::PASS, 'FRONTEND_URL', $url),
            str_starts_with($url, 'https://') && ! preg_match('#^https://(localhost|127\.)#', $url) => self::row(self::WARN, 'FRONTEND_URL', "{$url} (expected {$expected})"),
            default => self::row(self::FAIL, 'FRONTEND_URL', "{$url} is not the https shop address (expected {$expected})."),
        };
    }

    private static function cors(string $site): array
    {
        $origins = array_values((array) config('cors.allowed_origins'));
        $list = $origins === [] ? '(none)' : implode(', ', $origins);

        if (! in_array($site, $origins, true)) {
            return self::row(self::FAIL, 'CORS allowed origins', "{$list} - does not include {$site}, so the shop's browser calls would be refused.");
        }

        $local = array_values(array_filter($origins, fn (string $o): bool => (bool) preg_match('#^https?://(localhost|127\.|\[::1\])#', $o)));

        if ($local !== []) {
            return self::row(self::WARN, 'CORS allowed origins', "{$list} - includes a local address that a live shop does not need.");
        }

        return self::row(self::PASS, 'CORS allowed origins', $list);
    }

    private static function sanctum(): array
    {
        $domains = array_values(array_filter(array_map('trim', (array) config('sanctum.stateful'))));

        return self::row(self::PASS, 'Sanctum stateful domains', ($domains === [] ? '(none)' : implode(', ', $domains)).' (the shop signs customers in with tokens, so this only matters for cookie sessions)');
    }

    /** @return list<array{status: string, label: string, detail: string}> */
    private static function mail(): array
    {
        $mailer = (string) config('mail.default');
        $from = (string) config('mail.from.address');

        if (in_array($mailer, ['log', 'array'], true)) {
            return [self::row(self::FAIL, 'Mail', "MAIL_MAILER is \"{$mailer}\": emails are written to the log, not sent.")];
        }

        $detail = ["mailer {$mailer}"];
        $missing = [];

        if ($mailer === 'smtp') {
            $host = (string) config('mail.mailers.smtp.host');
            $detail[] = 'host '.($host !== '' ? $host : 'missing').':'.config('mail.mailers.smtp.port');
            $detail[] = 'username '.(filled(config('mail.mailers.smtp.username')) ? 'set' : 'missing');
            $detail[] = 'password '.(filled(config('mail.mailers.smtp.password')) ? 'set' : 'missing');

            foreach (['host' => $host, 'username' => (string) config('mail.mailers.smtp.username'), 'password' => (string) config('mail.mailers.smtp.password')] as $name => $value) {
                if ($value === '') {
                    $missing[] = $name;
                }
            }

            if (in_array($host, ['127.0.0.1', 'localhost', 'mailpit'], true)) {
                $missing[] = 'a real host';
            }
        }

        $detail[] = 'from '.($from !== '' ? $from : 'missing');

        if ($from === '' || str_ends_with($from, '@example.com')) {
            $missing[] = 'a real from address';
        }

        $rows = [self::row($missing === [] ? self::PASS : self::FAIL, 'Mail', implode(', ', $detail).($missing === [] ? '' : '; needs '.implode(', ', $missing)).' (nothing was sent by this check)')];

        $failed = SystemEvents::countSince('mail_failed', now()->subDays(7));
        if ($failed > 0) {
            $rows[] = self::row(self::WARN, 'Mail failures', "{$failed} email(s) could not be sent in the last 7 days (Settings > System health has the reason).");
        }

        return $rows;
    }

    private static function scheduler(): array
    {
        $beat = SystemEvents::latest('scheduler');

        if ($beat === null) {
            return self::row(self::FAIL, 'Scheduler heartbeat', 'it has never run. The cron line (schedule:run every minute) is not set up.');
        }

        $minutes = (int) abs(now()->diffInMinutes($beat->updated_at, true));

        return $minutes <= 5
            ? self::row(self::PASS, 'Scheduler heartbeat', "last ran {$minutes} minute(s) ago")
            : self::row(self::FAIL, 'Scheduler heartbeat', "last ran {$minutes} minutes ago (it must be within 5): the cron line has stopped.");
    }

    private static function storageLink(): array
    {
        return is_link(public_path('storage')) || is_dir(public_path('storage'))
            ? self::row(self::PASS, 'Storage link', 'public/storage is in place')
            : self::row(self::FAIL, 'Storage link', 'public/storage is missing, so uploaded pictures will not show. Run: php artisan storage:link');
    }

    /** @return list<array{status: string, label: string, detail: string}> */
    private static function wallets(): array
    {
        $settings = PaymentSetting::query()->first();
        $rows = [];
        $available = ($settings?->cod_enabled ?? true) ? ['cash on delivery'] : [];

        foreach (WalletPayments::METHODS as $method) {
            $number = $settings?->numberFor($method);

            if ($number === null) {
                $rows[] = self::row(self::PASS, WalletPayments::LABELS[$method], 'not offered (no merchant number saved)');

                continue;
            }

            $available[] = WalletPayments::LABELS[$method];
            $rows[] = WalletPayments::isValidNumber(WalletPayments::normalizeNumber($number))
                ? self::row(self::PASS, WalletPayments::LABELS[$method], 'offered, number '.self::mask($number))
                : self::row(self::WARN, WalletPayments::LABELS[$method], 'offered, but the number '.self::mask($number).' is not a Bangladeshi mobile number');
        }

        array_unshift($rows, $available === []
            ? self::row(self::FAIL, 'Payment methods', 'none: cash on delivery is off and no wallet number is saved, so nobody can order.')
            : self::row(self::PASS, 'Payment methods', implode(', ', $available)));

        return $rows;
    }

    /** @return list<array{status: string, label: string, detail: string}> */
    private static function products(): array
    {
        $withPhoto = Product::query()->where('is_active', true)->whereHas('images')->count();
        $withoutPhoto = Product::query()->where('is_active', true)->whereDoesntHave('images')->count();

        $rows = [$withPhoto > 0
            ? self::row(self::PASS, 'Products with a photo', "{$withPhoto} active")
            : self::row(self::FAIL, 'Products with a photo', 'no active product has a photo')];

        if ($withoutPhoto > 0) {
            $rows[] = self::row(self::WARN, 'Products without a photo', "{$withoutPhoto} active product(s) have none and show a plain card");
        }

        return $rows;
    }

    /** @return list<array{status: string, label: string, detail: string}> */
    private static function pages(): array
    {
        $slugs = Page::query()->pluck('slug')->map(fn ($slug) => strtolower((string) $slug))->all();
        $rows = [];

        foreach (self::PAGES as $label => $accepted) {
            $found = array_values(array_intersect($accepted, $slugs));
            $rows[] = $found !== []
                ? self::row(self::PASS, "Page: {$label}", $found[0])
                : self::row(self::FAIL, "Page: {$label}", 'missing (looked for '.implode(', ', $accepted).')');
        }

        return $rows;
    }

    /** @return list<array{status: string, label: string, detail: string}> */
    private static function marketing(): array
    {
        $settings = MarketingSetting::query()->first();
        $rows = [];

        foreach (['gtm_container_id' => 'Google Tag Manager', 'meta_pixel_id' => 'Meta pixel', 'ga4_id' => 'Google Analytics 4', 'tiktok_pixel_id' => 'TikTok pixel'] as $field => $label) {
            $value = trim((string) $settings?->{$field});
            $rows[] = $value !== '' ? self::row(self::PASS, $label, self::mask($value)) : self::row(self::WARN, $label, 'missing');
        }

        foreach (['meta_capi_access_token' => 'Meta conversions token', 'tiktok_events_api_access_token' => 'TikTok events token'] as $field => $label) {
            $rows[] = filled($settings?->{$field}) ? self::row(self::PASS, $label, 'set') : self::row(self::WARN, $label, 'missing');
        }

        foreach (['meta_test_event_code' => 'Meta test event code', 'tiktok_test_event_code' => 'TikTok test event code'] as $field => $label) {
            if (filled($settings?->{$field})) {
                $rows[] = self::row(self::WARN, $label, 'is still set: sales would be sent as tests. Clear it before opening.');
            }
        }

        return $rows;
    }

    /** @return list<array{status: string, label: string, detail: string}> */
    private static function socialLogin(): array
    {
        $rows = [];

        foreach (IntegrationSetting::SOCIAL_PROVIDERS as $provider => $meta) {
            $state = IntegrationSetting::socialLoginState($provider);
            $callback = IntegrationSetting::callbackUrl($provider);

            $rows[] = match ($state) {
                'on' => self::row(self::PASS, "{$meta['label']} login", "on; callback {$callback}"),
                'off' => self::row(self::WARN, "{$meta['label']} login", "set up but switched off; callback {$callback}"),
                default => self::row(self::WARN, "{$meta['label']} login", 'not set up (no client id and secret yet)'),
            };
        }

        return $rows;
    }

    private static function backup(): array
    {
        $newest = (new BackupService)->all()[0] ?? null;

        if ($newest === null) {
            return self::row(self::WARN, 'Newest backup', 'none yet (the first is made at 02:30 Dhaka time, or press "Back up now" in Settings > System health)');
        }

        $hours = (int) abs(now()->diffInHours(Carbon::createFromTimestamp($newest['modified']), true));
        $detail = "{$newest['kind']} backup, {$hours} hour(s) old";

        return $hours > (int) config('backup.stale_after_hours')
            ? self::row(self::FAIL, 'Newest backup', "{$detail}: too old, the nightly backup is not running")
            : self::row(self::PASS, 'Newest backup', $detail);
    }
}
