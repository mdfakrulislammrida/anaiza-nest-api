<?php

namespace App\Support;

use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\ImageManager;

/**
 * The small product pictures in emails and on the invoice.
 *
 * Mail apps do not show WebP reliably and shrink big pictures badly, so each picture is a 128 px square JPEG (shown at 64 px, which
 * keeps it sharp on a phone), made once from the product's own image, flattened onto linen where it has transparency, and kept on the
 * public disk in email-thumbs/. The file name is a hash of the source, so a new product image makes a new thumbnail and an old
 * one is never served for it.
 *
 * An order line keeps the path of its own thumbnail (order_items.image_path), written when the order is placed: that is the snapshot, so
 * an email or invoice from last month still shows the piece as it was ordered, whatever has been done to the product since.
 */
final class EmailThumbs
{
    public const DIRECTORY = 'email-thumbs';

    /** The picture's size in pixels, and the size it is shown at in the email. */
    public const SIZE = 128;

    public const DISPLAY = 64;

    private const LINEN = 'EDE4D3';

    /**
     * The image to make a thumbnail from: the chosen variant's own picture when it has one, otherwise the product's first picture.
     * A path on the public disk, or an address.
     */
    public static function sourceFor(?Product $product, ?ProductVariant $variant = null): ?string
    {
        $image = $variant?->image ?? $product?->images()->orderBy('sort_order')->orderBy('id')->first();

        $source = $image?->url_400 ?: $image?->url;

        return filled($source) ? (string) $source : null;
    }

    /**
     * Makes (once) the thumbnail of a source and returns its path on the public disk, or null when it cannot be made.
     */
    public static function make(?string $source): ?string
    {
        if (blank($source)) {
            return null;
        }

        $disk = Storage::disk('public');
        $path = self::DIRECTORY.'/'.sha1($source).'-'.self::SIZE.'.jpg';

        if ($disk->exists($path)) {
            return $path;
        }

        try {
            if (Str::startsWith($source, ['http://', 'https://'])) {
                $response = Http::timeout(5)->get($source);

                if (! $response->successful()) {
                    return null;
                }

                $binary = $response->body();
            } else {
                if (! $disk->exists($source)) {
                    return null;
                }

                $binary = (string) $disk->get($source);
            }

            $picture = (new ImageManager(new Driver, backgroundColor: self::LINEN))->decodeBinary($binary);
            $picture->cover(self::SIZE, self::SIZE);

            $disk->makeDirectory(self::DIRECTORY);
            $disk->put($path, (string) $picture->encode(new JpegEncoder(quality: 82)));
        } catch (\Throwable $e) {
            Log::warning('An email thumbnail could not be made.', ['source' => Str::limit($source, 120), 'error' => $e->getMessage()]);

            return null;
        }

        return $path;
    }

    /**
     * The path to store on an order line as it is placed. Never throws: an order must not fail over a picture.
     */
    public static function snapshot(?Product $product, ?ProductVariant $variant = null): ?string
    {
        try {
            return self::make(self::sourceFor($product, $variant));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The address of a stored thumbnail, or null when there is none (or its file has gone).
     */
    public static function url(?string $path): ?string
    {
        if (blank($path) || ! Storage::disk('public')->exists($path)) {
            return null;
        }

        $url = Storage::disk('public')->url($path);

        // A mail app has no site to resolve a relative address against: it is always the full address.
        return Str::startsWith($url, ['http://', 'https://']) ? $url : rtrim((string) config('app.url'), '/').'/'.ltrim($url, '/');
    }

    /**
     * The thumbnail for an order line: its own snapshot. A line placed before snapshots existed has none; it is then made from the
     * product as it is now, and kept, so the line has a snapshot from then on.
     */
    public static function urlForItem(OrderItem $item): ?string
    {
        if (filled($item->image_path) && ($url = self::url($item->image_path)) !== null) {
            return $url;
        }

        $path = self::pathFromProduct($item);

        if ($path === null) {
            return null;
        }

        if ($item->exists) {
            $item->forceFill(['image_path' => $path])->saveQuietly();
        }

        return self::url($path);
    }

    /**
     * The thumbnail for a cart line (a saved checkout): the product's, from its id and variant.
     *
     * @param  array<string, mixed>  $line
     */
    public static function urlForCartLine(array $line): ?string
    {
        $product = isset($line['product_id']) ? Product::query()->find((int) $line['product_id']) : null;
        $variant = $product && ! empty($line['variant_id']) ? ProductVariant::query()->where('product_id', $product->id)->find((int) $line['variant_id']) : null;

        return self::url(self::snapshot($product, $variant));
    }

    /**
     * The thumbnail as a data address, for the invoice PDF (which then needs no network and never shows a broken picture).
     */
    public static function dataUriForItem(OrderItem $item): ?string
    {
        self::urlForItem($item);

        if (blank($item->image_path) || ! Storage::disk('public')->exists($item->image_path)) {
            return null;
        }

        return 'data:image/jpeg;base64,'.base64_encode((string) Storage::disk('public')->get($item->image_path));
    }

    /** The letter shown on the linen tile of a line with no picture. */
    public static function initial(?string $name): string
    {
        $first = mb_substr(trim((string) $name), 0, 1);

        return $first === '' ? '·' : mb_strtoupper($first);
    }

    /**
     * Fills the picture of every order line that has none, from its product as it is now (there is no earlier picture to go back
     * to). Lines whose product is gone, or has no picture, stay empty and show the letter tile. Safe to run again.
     *
     * @return array{filled: int, left: int}
     */
    public static function backfill(): array
    {
        $filled = 0;
        $left = 0;

        OrderItem::query()->whereNull('image_path')->whereNotNull('product_id')->with('product')->orderBy('id')->chunkById(100, function ($items) use (&$filled, &$left): void {
            foreach ($items as $item) {
                $path = self::pathFromProduct($item);

                if ($path === null) {
                    $left++;

                    continue;
                }

                $item->forceFill(['image_path' => $path])->saveQuietly();
                $filled++;
            }
        });

        return ['filled' => $filled, 'left' => $left];
    }

    private static function pathFromProduct(OrderItem $item): ?string
    {
        $product = $item->product;

        if ($product === null) {
            return null;
        }

        // A line records its variant by name and value, so the variant is found the same way.
        $variant = filled($item->variant_name)
            ? ProductVariant::query()->where('product_id', $product->id)->where('name', $item->variant_name)->where('value', $item->variant_value)->first()
            : null;

        return self::snapshot($product, $variant);
    }
}
