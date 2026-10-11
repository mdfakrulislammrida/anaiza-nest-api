<?php

namespace App\Http\Controllers;

use App\Models\SiteSetting;
use App\Support\Feeds\FeedBuilder;
use App\Support\Feeds\FeedWriter;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * The three product feeds, on the API's own domain. Each is always built from the live catalogue and kept for an hour, and
 * each needs the shop's feed token in the address (a missing or wrong one is a plain 404, so the feeds are not discoverable).
 */
class FeedController extends Controller
{
    public const CACHE_SECONDS = 3600;

    /** @var array<string, array{type: string, file: string}> */
    public const FEEDS = [
        'google' => ['type' => 'application/xml; charset=UTF-8', 'file' => 'google.xml'],
        'meta' => ['type' => 'text/csv; charset=UTF-8', 'file' => 'meta.csv'],
        'tiktok' => ['type' => 'text/csv; charset=UTF-8', 'file' => 'tiktok.csv'],
    ];

    public static function cacheKey(string $platform): string
    {
        return "feeds.{$platform}";
    }

    public static function forgetAll(): void
    {
        foreach (array_keys(self::FEEDS) as $platform) {
            Cache::forget(self::cacheKey($platform));
        }
    }

    /**
     * The feed's text, built fresh.
     */
    public static function render(string $platform, ?SiteSetting $site = null): string
    {
        $site ??= SiteSetting::query()->first() ?? new SiteSetting;
        $items = FeedBuilder::forPlatform((new FeedBuilder($site))->build()['items'], $platform);

        return $platform === 'google' ? FeedWriter::google($items, $site) : FeedWriter::csv($items, $platform);
    }

    public function show(Request $request, string $platform): Response
    {
        abort_unless(isset(self::FEEDS[$platform]), 404);

        $site = SiteSetting::query()->first();
        $token = (string) $site?->feed_token;
        $given = (string) $request->query('token', '');

        abort_unless($token !== '' && $given !== '' && hash_equals($token, $given), 404);

        $body = Cache::remember(self::cacheKey($platform), self::CACHE_SECONDS, fn () => self::render($platform, $site));

        $headers = [
            'Content-Type' => self::FEEDS[$platform]['type'],
            'Cache-Control' => 'private, max-age='.self::CACHE_SECONDS,
            'X-Robots-Tag' => 'noindex',
        ];

        if ($request->boolean('download')) {
            $headers['Content-Disposition'] = 'attachment; filename="'.self::FEEDS[$platform]['file'].'"';
        }

        return response($body, 200, $headers);
    }
}
