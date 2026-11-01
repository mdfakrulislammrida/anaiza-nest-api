<?php

namespace App\Support;

use App\Models\OrderItem;
use App\Models\ProductImage;

/**
 * The small picture shown beside a line on the admin order page. Read-only: unlike EmailThumbs::urlForItem it never makes or saves a thumbnail.
 *
 * In order: the picture the line saved when the order was placed (a 128 px square), then the chosen variant's own picture, then the product's
 * main picture (the smallest size it has, never the full-size file), then nothing, for which the page shows a neutral tile. A product that has been
 * deleted since leaves the saved picture, if there is one, and otherwise the tile.
 */
final class OrderItemPicture
{
    public static function url(OrderItem $item): ?string
    {
        if (filled($item->image_path) && ($saved = EmailThumbs::url($item->image_path)) !== null) {
            return $saved;
        }

        $product = $item->product;

        if ($product === null) {
            return null;
        }

        $variant = filled($item->variant_name)
            ? $product->variants->first(fn ($variant) => $variant->name === $item->variant_name && $variant->value === $item->variant_value)
            : null;

        $image = $variant?->image ?? $product->images->sortBy([['sort_order', 'asc'], ['id', 'asc']])->first();

        return self::small($image);
    }

    private static function small(?ProductImage $image): ?string
    {
        $path = $image?->url_400 ?: $image?->url;

        return filled($path) ? Seo::absolute((string) $path) : null;
    }

    /** A neutral square for a line with no picture: a pale tile with a plain frame. */
    public static function placeholder(): string
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 48 48"><rect width="48" height="48" fill="#EDEBE6"/>'
            .'<rect x="14" y="15" width="20" height="18" rx="2" fill="none" stroke="#B9B4A9" stroke-width="2"/><circle cx="20" cy="22" r="2" fill="#B9B4A9"/>'
            .'<path d="M15 31l6-6 4 4 3-3 5 5" fill="none" stroke="#B9B4A9" stroke-width="2"/></svg>';

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
