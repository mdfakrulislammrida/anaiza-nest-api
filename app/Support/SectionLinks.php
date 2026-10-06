<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Turns the link chosen for a "Shop by occasion" tile into the href the storefront uses. The admin
 * picks a category, a page, the gift finder or a custom URL; only the slug is stored, so renaming a
 * category's name never breaks the tile (changing its slug would, and the tile then simply drops out).
 */
final class SectionLinks
{
    public const TYPES = [
        'category' => 'A category',
        'page' => 'A page',
        'gift_finder' => 'The gift finder',
        'custom' => 'A custom URL',
    ];

    /**
     * @param  array<string, mixed>  $tile
     */
    public static function href(array $tile): ?string
    {
        $slug = fn (string $key): ?string => filled($tile[$key] ?? null) ? trim((string) $tile[$key]) : null;

        return match ($tile['link_type'] ?? 'custom') {
            'category' => ($category = $slug('link_category')) ? '/category/'.rawurlencode($category) : null,
            'page' => ($page = $slug('link_page')) ? '/pages/'.rawurlencode($page) : null,
            'gift_finder' => '/gift-finder',
            default => self::safeUrl($slug('custom_url')),
        };
    }

    /**
     * A site path or an http(s) address; anything else (javascript:, data:, bare text) is dropped.
     */
    private static function safeUrl(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        if (Str::startsWith($url, '/') && ! Str::startsWith($url, '//')) {
            return $url;
        }

        return Str::startsWith($url, ['https://', 'http://']) ? $url : null;
    }
}
