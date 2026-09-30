<?php

namespace App\Observers;

use App\Models\ProductImage;
use App\Support\WebpImage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Converts an admin-uploaded product image to WebP and generates the
 * smaller companion sizes <img srcset> needs, whenever `url` changes.
 *
 * `url` itself is overwritten to point at the WebP output (capped at
 * 1200px wide) -- every existing caller of `$image->url`/`display_url`
 * gets the optimized image for free, no code changes needed elsewhere.
 * `url_400`/`url_800` are only generated when the source is actually wider
 * than that breakpoint; upscaling a smaller source image would just waste
 * bytes on a blurrier copy.
 */
class ProductImageObserver
{
    private const WIDTHS = [400, 800, 1200];

    public function saving(ProductImage $image): void
    {
        if (! $image->isDirty('url')) {
            return;
        }

        $originalPath = $image->url;

        if (! $originalPath || Str::startsWith($originalPath, ['http://', 'https://'])) {
            // An externally-hosted URL (e.g. hand-entered/seeded) can't be
            // read off our own disk -- leave it exactly as given.
            return;
        }

        try {
            $disk = Storage::disk('public');

            if (! $disk->exists($originalPath)) {
                return;
            }

            $source = WebpImage::decode($disk->path($originalPath));

            $directory = 'products/generated';
            $disk->makeDirectory($directory);
            $basename = (string) Str::uuid();

            $largestWidth = self::WIDTHS[array_key_last(self::WIDTHS)];
            $generated = [];

            foreach (self::WIDTHS as $targetWidth) {
                if ($source->width() < $targetWidth && $targetWidth !== $largestWidth) {
                    // Don't upscale for the smaller breakpoints, but the
                    // largest one always gets a copy (see below) to become
                    // the new `url`, even if that just means re-encoding at
                    // the source's own size.
                    continue;
                }

                $resized = clone $source;
                if ($source->width() > $targetWidth) {
                    $resized->scaleDown(width: $targetWidth);
                }

                $path = "{$directory}/{$basename}-{$targetWidth}.webp";
                $disk->put($path, WebpImage::encode($resized));
                $generated[$targetWidth] = $path;

                if ($targetWidth === $largestWidth) {
                    // `width`/`height` describe the `url` file below, not the
                    // original upload -- a consumer building a srcset width
                    // descriptor or an <img width height> from these needs
                    // the dimensions of the file it actually downloads.
                    $image->width = $resized->width();
                    $image->height = $resized->height();
                }
            }

            // The biggest generated size becomes the canonical `url` --
            // guaranteed to exist even if the source was smaller than every
            // breakpoint (the loop above always processes the largest).
            $image->url = $generated[$largestWidth] ?? $originalPath;
            $image->url_400 = $generated[400] ?? null;
            $image->url_800 = $generated[800] ?? null;
        } catch (\Throwable $e) {
            // A conversion failure must never block saving the image --
            // the admin still gets their original upload, just without the
            // generated sizes.
            Log::error('Product image WebP conversion failed.', [
                'path' => $originalPath,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
