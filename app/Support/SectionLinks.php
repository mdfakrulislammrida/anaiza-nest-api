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

        // A category or page is stored by its slug. If the slug has been changed since, the link follows it (App\Support\Slugs), so a tile never points at an old address.
        return match ($tile['link_type'] ?? 'custom') {
            'category' => ($category = $slug('link_category')) ? '/category/'.rawurlencode(Slugs::current('category', $category)) : null,
            'page' => ($page = $slug('link_page')) ? '/pages/'.rawurlencode(Slugs::current('page', $page)) : null,
            'gift_finder' => '/gift-finder',
            default => self::safeUrl($slug('custom_url')),
        };
    }

    /**
     * What a tile points at, for the API: the kind of target, the slug when it is a category or a page (the current one), and the path or address.
     *
     * @param  array<string, mixed>  $tile
     * @return array{kind: string, slug: ?string, path: ?string}
     */
    public static function describe(array $tile): array
    {
        $kind = (string) ($tile['link_type'] ?? 'custom');
        $href = self::href($tile);
        $slug = match ($kind) {
            'category' => filled($tile['link_category'] ?? null) ? Slugs::current('category', trim((string) $tile['link_category'])) : null,
            'page' => filled($tile['link_page'] ?? null) ? Slugs::current('page', trim((string) $tile['link_page'])) : null,
            default => null,
        };

        return ['kind' => $kind, 'slug' => $slug, 'path' => $href];
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
