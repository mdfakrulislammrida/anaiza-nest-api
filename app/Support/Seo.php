<?php

namespace App\Support;

/**
 * The one shape of the "seo" block every public page answer carries: { title, description, image }.
 *
 * The image is the first usable one of the candidates the caller lists (the page's own share image first, then the picture the page already has),
 * as a full https address, so a share card, a search result and a product feed can all use it as it is. When there is none, it is null and the
 * storefront's own default share image applies (that file belongs to the storefront, not to the admin).
 */
final class Seo
{
    /**
     * @return array{title: ?string, description: ?string, image: ?string}
     */
    public static function block(?string $title, ?string $description, ?string ...$images): array
    {
        return [
            'title' => filled($title) ? $title : null,
            'description' => filled($description) ? $description : null,
            'image' => self::image(...$images),
        ];
    }

    /** The first candidate that is a picture, as an absolute https address. */
    public static function image(?string ...$candidates): ?string
    {
        foreach ($candidates as $candidate) {
            $url = self::absolute($candidate);

            if ($url !== null) {
                return $url;
            }
        }

        return null;
    }

    /** A stored value or a URL as a full address on https. Only a machine's own address (localhost, .test) may stay on http. */
    public static function absolute(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        if (blank($value)) {
            return null;
        }

        // An address with no scheme ("//cdn.example.com/a.jpg") is somebody else's server, not a path on this disk.
        $url = str_starts_with($value, '//') ? 'https:'.$value : MediaUrl::resolve($value);

        // A disk set up with a relative address ("/storage/a.jpg") is completed with this shop's own.
        if (is_string($url) && str_starts_with($url, '/')) {
            $url = rtrim((string) config('app.url'), '/').$url;
        }

        if (! is_string($url) || (! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://'))) {
            return null;
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $local = $host === 'localhost' || $host === '127.0.0.1' || $host === '[::1]' || str_ends_with($host, '.test') || str_ends_with($host, '.localhost');

        return ! $local && str_starts_with($url, 'http://') ? 'https://'.substr($url, 7) : $url;
    }
}
