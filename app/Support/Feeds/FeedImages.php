<?php

namespace App\Support\Feeds;

use App\Models\ProductImage;
use App\Support\WebpImage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Encoders\JpegEncoder;

/**
 * JPEG renditions for the product feeds.
 *
 * Our stored product images are WebP, but Meta accepts only JPEG or PNG (and TikTok only JPG or PNG), so a WebP address must
 * never appear in a feed. Each feed image is a JPEG made once from the stored file, at least 1024px on its long side (Meta
 * asks for 500px or more; a smaller source is scaled up to 1024) and at most 2000px, and kept on the public disk. The file name
 * carries a hash of the source path, so replacing a product image produces a new rendition rather than serving a stale one.
 */
final class FeedImages
{
    public const MIN_LONG_SIDE = 1024;

    public const MAX_LONG_SIDE = 2000;

    public const DIRECTORY = 'feeds/images';

    /**
     * The address of this image's JPEG rendition, or null when none can be made.
     */
    public static function url(ProductImage $image): ?string
    {
        $source = (string) $image->url;

        if ($source === '') {
            return null;
        }

        // An image hosted elsewhere cannot be read from our disk: it is usable only if it already is a JPEG or PNG.
        if (Str::startsWith($source, ['http://', 'https://'])) {
            return preg_match('/\.(jpe?g|png)(\?.*)?$/i', $source) === 1 ? $source : null;
        }

        $disk = Storage::disk('public');
        $path = self::DIRECTORY.'/'.$image->id.'-'.substr(md5($source), 0, 8).'.jpg';

        if ($disk->exists($path)) {
            return $disk->url($path);
        }

        try {
            if (! $disk->exists($source)) {
                return null;
            }

            $picture = WebpImage::decode($disk->path($source));
            $long = max($picture->width(), $picture->height());

            if ($long < self::MIN_LONG_SIDE) {
                $picture->width() >= $picture->height() ? $picture->scale(width: self::MIN_LONG_SIDE) : $picture->scale(height: self::MIN_LONG_SIDE);
            } elseif ($long > self::MAX_LONG_SIDE) {
                $picture->scaleDown(...($picture->width() >= $picture->height() ? ['width' => self::MAX_LONG_SIDE] : ['height' => self::MAX_LONG_SIDE]));
            }

            $disk->makeDirectory(self::DIRECTORY);
            $disk->put($path, (string) $picture->encode(new JpegEncoder(quality: 86)));
        } catch (\Throwable $e) {
            Log::warning('A feed image could not be made.', ['image_id' => $image->id, 'error' => $e->getMessage()]);

            return null;
        }

        return $disk->url($path);
    }

    /**
     * The smaller side of the stored image, when we know it: what the platforms' 500px minimum is measured against.
     */
    public static function shortSide(ProductImage $image): ?int
    {
        return $image->width && $image->height ? min($image->width, $image->height) : null;
    }
}
