<?php

namespace App\Observers;

use App\Models\HomepageSection;
use App\Support\WebpImage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Runs an "occasion" tile's uploaded picture through the WebP pipeline: decoded, capped at 600px wide
 * (a tile is never shown larger than a phone column) and stored as WebP, so the homepage never
 * carries a heavy original. Pictures that are already converted, or are external URLs, are left alone.
 * A conversion failure keeps the original upload and is logged; it never blocks saving the section.
 */
class HomepageSectionObserver
{
    private const TILE_MAX_WIDTH = 600;

    private const DIRECTORY = 'occasions/generated';

    public function saving(HomepageSection $section): void
    {
        $tiles = $section->tiles;

        if (! is_array($tiles) || $tiles === []) {
            return;
        }

        $disk = Storage::disk('public');

        foreach ($tiles as $index => $tile) {
            $path = $tile['image'] ?? null;

            if (! is_string($path) || $path === '' || Str::startsWith($path, ['http://', 'https://']) || Str::startsWith($path, self::DIRECTORY.'/')) {
                continue;
            }

            try {
                if (! $disk->exists($path)) {
                    continue;
                }

                $image = WebpImage::decode($disk->path($path));
                if ($image->width() > self::TILE_MAX_WIDTH) {
                    $image->scaleDown(width: self::TILE_MAX_WIDTH);
                }

                $disk->makeDirectory(self::DIRECTORY);
                $converted = self::DIRECTORY.'/'.Str::uuid().'-tile.webp';
                $disk->put($converted, WebpImage::encode($image));

                $tiles[$index]['image'] = $converted;
                $disk->delete($path);
            } catch (\Throwable $e) {
                Log::error('Occasion tile image conversion failed.', ['section_id' => $section->id, 'error' => $e->getMessage()]);
            }
        }

        $section->tiles = $tiles;
    }
}
