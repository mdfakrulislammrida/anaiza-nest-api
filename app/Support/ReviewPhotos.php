<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Review photos go through the same WebP pipeline as product images: decoded, capped in width, re-encoded as WebP, with a
 * small copy for the list. The visitor's original file is never stored, so a heavy or odd file cannot reach the page.
 */
final class ReviewPhotos
{
    private const FULL_WIDTH = 1000;

    private const THUMB_WIDTH = 400;

    private const DIRECTORY = 'reviews/generated';

    /**
     * @param  array<int, UploadedFile>  $files
     * @return list<array{url: string, thumb: string}>
     *
     * @throws \Throwable when a file cannot be read as an image
     */
    public static function store(array $files): array
    {
        $disk = Storage::disk('public');
        $disk->makeDirectory(self::DIRECTORY);
        $stored = [];

        try {
            foreach ($files as $file) {
                $name = (string) Str::uuid();
                $full = WebpImage::decode($file->getRealPath());
                $thumb = clone $full;

                if ($full->width() > self::FULL_WIDTH) {
                    $full->scaleDown(width: self::FULL_WIDTH);
                }
                if ($thumb->width() > self::THUMB_WIDTH) {
                    $thumb->scaleDown(width: self::THUMB_WIDTH);
                }

                $paths = [
                    'url' => self::DIRECTORY."/{$name}.webp",
                    'thumb' => self::DIRECTORY."/{$name}-400.webp",
                ];
                $disk->put($paths['url'], WebpImage::encode($full));
                $disk->put($paths['thumb'], WebpImage::encode($thumb));
                $stored[] = $paths;
            }
        } catch (\Throwable $e) {
            // A photo that cannot be read must not leave the ones before it behind.
            foreach ($stored as $paths) {
                $disk->delete([$paths['url'], $paths['thumb']]);
            }

            throw $e;
        }

        return $stored;
    }

    /**
     * @param  array<int, array{url?: string, thumb?: string}>|null  $photos
     */
    public static function delete(?array $photos): void
    {
        $paths = collect($photos ?? [])->flatMap(fn (array $photo) => [$photo['url'] ?? null, $photo['thumb'] ?? null])->filter()->all();

        if ($paths !== []) {
            Storage::disk('public')->delete($paths);
        }
    }
}
