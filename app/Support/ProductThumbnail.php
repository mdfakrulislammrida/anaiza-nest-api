<?php

namespace App\Support;

use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The small picture shown for a product in the admin (the Products list, its phone card, the best-sellers table).
 *
 * A product has no picture of its own: its pictures are rows of its gallery (product_images), each with the original and smaller copies, and a variant may
 * point at one of them. In order: the main picture (the gallery's first by position), then the rest of the gallery, then each variant's picture, then nothing
 * (the list shows a neutral tile). Each picture gives its smallest copy first (400 px, then 800, then the original), and a copy whose file is no longer on this
 * server's disk is skipped for the next, so a missing file never leaves a broken picture or breaks the page.
 *
 * Read-only: nothing is made or saved. Eager load images and variants.image for a list.
 */
final class ProductThumbnail
{
    public static function url(Product $product): ?string
    {
        $pictures = $product->images->sortBy([['sort_order', 'asc'], ['id', 'asc']])->values()->all();

        foreach ($product->variants as $variant) {
            if ($variant->image !== null) {
                $pictures[] = $variant->image;
            }
        }

        foreach ($pictures as $picture) {
            $url = self::smallestThatExists($picture);

            if ($url !== null) {
                return $url;
            }
        }

        return null;
    }

    /** One gallery picture's smallest usable copy, by the same rule as the list (the product's pictures table in the admin uses it too). */
    public static function imageUrl(ProductImage $picture): ?string
    {
        return self::smallestThatExists($picture);
    }

    private static function smallestThatExists(ProductImage $picture): ?string
    {
        foreach ([$picture->url_400, $picture->url_800, $picture->url] as $stored) {
            if (blank($stored)) {
                continue;
            }

            // Another server's picture (an address typed in) is used as it is; one of ours must still be on the disk.
            if (Str::startsWith($stored, ['http://', 'https://']) || Storage::disk('public')->exists($stored)) {
                return MediaUrl::resolve($stored);
            }
        }

        return null;
    }
}
