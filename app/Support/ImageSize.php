<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The pixel size of a picture kept on the public disk, for the settings API (so a page can reserve the space before the picture loads).
 * Read from the file itself, remembered until the file changes, and never fetched from another server: a picture that is not on this disk has no size.
 */
final class ImageSize
{
    /**
     * @return array{width: ?int, height: ?int}
     */
    public static function of(?string $stored): array
    {
        $none = ['width' => null, 'height' => null];

        if (blank($stored)) {
            return $none;
        }

        $path = $stored;

        if (Str::startsWith($stored, ['http://', 'https://'])) {
            $urlPath = (string) parse_url($stored, PHP_URL_PATH);

            if (! Str::startsWith($urlPath, '/storage/')) {
                return $none;
            }

            $path = rawurldecode(Str::after($urlPath, '/storage/'));
        }

        try {
            $disk = Storage::disk('public');

            if (! $disk->exists($path)) {
                return $none;
            }

            return Cache::remember('image-size:'.sha1($path.'|'.$disk->lastModified($path).'|'.$disk->size($path)), now()->addDay(), fn (): array => self::read($disk->get($path), $path));
        } catch (\Throwable) {
            return $none;
        }
    }

    /**
     * @return array{width: ?int, height: ?int}
     */
    private static function read(string $binary, string $path): array
    {
        if (Str::endsWith(strtolower($path), '.svg')) {
            return self::svg(substr($binary, 0, 4096));
        }

        $info = @getimagesizefromstring($binary);

        return $info === false ? ['width' => null, 'height' => null] : ['width' => (int) $info[0], 'height' => (int) $info[1]];
    }

    /**
     * @return array{width: ?int, height: ?int}
     */
    private static function svg(string $head): array
    {
        $number = fn (string $attribute): ?int => preg_match('/<svg[^>]*\s'.$attribute.'="([\d.]+)(?:px)?"/i', $head, $m) === 1 ? (int) round((float) $m[1]) : null;
        $width = $number('width');
        $height = $number('height');

        if (($width === null || $height === null) && preg_match('/viewBox="[\d.\-]+[ ,]+[\d.\-]+[ ,]+([\d.]+)[ ,]+([\d.]+)"/i', $head, $m) === 1) {
            $width ??= (int) round((float) $m[1]);
            $height ??= (int) round((float) $m[2]);
        }

        return ['width' => $width, 'height' => $height];
    }
}
