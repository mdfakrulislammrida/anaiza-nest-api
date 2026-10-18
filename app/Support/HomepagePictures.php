<?php

namespace App\Support;

use App\Models\SiteSetting;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * The pictures behind the homepage hero panel and its two side tiles (Site Settings > Homepage pictures).
 *
 * An upload is checked (JPEG, PNG or WebP, 5 MB, not absurdly large), converted to WebP, and written as a small set of
 * copies the browser chooses between with srcset: never larger than the original, the original deleted afterwards. The
 * shop does not crop: the storefront fits the picture to its panel from the centre with CSS (object-fit: cover). What is
 * stored per picture is the path of its largest copy (what the upload field shows) and the list of copies with their sizes.
 * A file that cannot be processed is never kept or served: the picture is simply left unset.
 */
class HomepagePictures
{
    public const MAX_BYTES = 5 * 1024 * 1024;

    /** Beyond this many pixels a photo is refused (decoding it would need more memory than the host has). */
    public const MAX_PIXELS = 36_000_000;

    public const ALLOWED_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    public const UPLOAD_DIRECTORY = 'site/uploads';

    public const GENERATED_DIRECTORY = 'site/generated';

    /**
     * The four pictures: the column holding the path, the column holding the copies, the widths made, and the size to
     * recommend (width, height) with a name for messages.
     *
     * @var array<string, array{renditions: string, widths: list<int>, recommended: array{0: int, 1: int}, label: string}>
     */
    public const PICTURES = [
        'hero_image' => ['renditions' => 'hero_image_renditions', 'widths' => [640, 1000, 1600], 'recommended' => [1600, 1000], 'label' => 'hero picture'],
        'hero_image_mobile' => ['renditions' => 'hero_image_mobile_renditions', 'widths' => [450, 900], 'recommended' => [900, 1200], 'label' => 'mobile hero picture'],
        'hot_deals_tile_image' => ['renditions' => 'hot_deals_tile_image_renditions', 'widths' => [600, 1200], 'recommended' => [1200, 750], 'label' => 'Special prices tile picture'],
        'new_arrivals_tile_image' => ['renditions' => 'new_arrivals_tile_image_renditions', 'widths' => [600, 1200], 'recommended' => [1200, 750], 'label' => 'New arrivals tile picture'],
    ];

    /**
     * Runs on every save of the site settings: processes a changed picture, and clears the copies of one that was removed.
     */
    public static function process(SiteSetting $settings): void
    {
        foreach (self::PICTURES as $field => $spec) {
            if (! $settings->isDirty($field)) {
                continue;
            }

            $disk = Storage::disk('public');
            $oldCopies = self::copiesOf($settings->getOriginal($spec['renditions']));
            $new = $settings->getAttribute($field);

            if (blank($new)) {
                $settings->setAttribute($spec['renditions'], null);
                self::deleteFiles($disk, $oldCopies);

                continue;
            }

            // Already one of the copies made here (the form sent the stored value back): nothing to do.
            if (Str::startsWith($new, self::GENERATED_DIRECTORY.'/')) {
                continue;
            }

            $copies = self::convert($disk, $new, $spec['widths']);

            if ($copies === null) {
                $settings->setAttribute($field, null);
                $settings->setAttribute($spec['renditions'], null);
            } else {
                $largest = end($copies);
                $settings->setAttribute($field, $largest['path']);
                $settings->setAttribute($spec['renditions'], $copies);
            }

            self::deleteFiles($disk, $oldCopies);
        }
    }

    /**
     * Turns the upload into WebP copies. Null when it is not an acceptable picture (and the upload is deleted either way).
     *
     * @param  list<int>  $widths
     * @return list<array{path: string, width: int, height: int}>|null smallest first
     */
    private static function convert(Filesystem $disk, string $upload, array $widths): ?array
    {
        $written = [];

        try {
            if (! $disk->exists($upload)) {
                return null;
            }

            $absolute = $disk->path($upload);
            $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($absolute);
            $size = @getimagesize($absolute);

            if (! in_array($mime, self::ALLOWED_TYPES, true) || $size === false || $disk->size($upload) > self::MAX_BYTES) {
                return null;
            }

            if ($size[0] * $size[1] > self::MAX_PIXELS) {
                return null;
            }

            $source = WebpImage::decode($absolute);
            $source->orient();

            $disk->makeDirectory(self::GENERATED_DIRECTORY);
            $name = (string) Str::uuid();

            // Never upscaled: only widths the original reaches get a copy, and the original's own width is added when it
            // falls between the sizes (or below all of them), so the largest copy is as large as the picture is.
            $made = array_values(array_filter($widths, fn (int $width) => $width <= $source->width()));
            if (! in_array($source->width(), $made, true) && $source->width() < max($widths)) {
                $made[] = $source->width();
            }
            sort($made);

            $copies = [];
            foreach ($made as $width) {
                $copy = clone $source;
                if ($copy->width() > $width) {
                    $copy->scaleDown(width: $width);
                }

                $path = self::GENERATED_DIRECTORY."/{$name}-{$width}.webp";
                $disk->put($path, WebpImage::encode($copy));
                $written[] = $path;
                $copies[] = ['path' => $path, 'width' => $copy->width(), 'height' => $copy->height()];
            }

            return $copies === [] ? null : $copies;
        } catch (\Throwable $e) {
            Log::error('Homepage picture conversion failed.', ['file' => $upload, 'error' => $e->getMessage()]);
            self::deleteFiles($disk, $written);

            return null;
        } finally {
            // The original is never kept: either its copies were made, or it was not an acceptable picture.
            if ($disk->exists($upload) && ! Str::startsWith($upload, self::GENERATED_DIRECTORY.'/')) {
                $disk->delete($upload);
            }
        }
    }

    /**
     * @return list<string>
     */
    private static function copiesOf(mixed $renditions): array
    {
        if (is_string($renditions)) {
            $renditions = json_decode($renditions, true);
        }

        return is_array($renditions) ? array_values(array_filter(array_column($renditions, 'path'), 'is_string')) : [];
    }

    /**
     * @param  list<string>  $paths
     */
    private static function deleteFiles(Filesystem $disk, array $paths): void
    {
        foreach ($paths as $path) {
            if (Str::startsWith($path, self::GENERATED_DIRECTORY.'/')) {
                $disk->delete($path);
            }
        }
    }

    // ---- What the API says

    /**
     * One picture as the storefront needs it: the largest copy, the srcset of all of them, and the size of the largest.
     *
     * @return array{src: string, srcset: string, width: int, height: int}|null
     */
    public static function set(SiteSetting $settings, string $field): ?array
    {
        $copies = $settings->getAttribute(self::PICTURES[$field]['renditions']);

        if (! is_array($copies) || $copies === []) {
            return null;
        }

        $urls = [];
        foreach ($copies as $copy) {
            if (! is_array($copy) || ! is_string($copy['path'] ?? null) || ! isset($copy['width'], $copy['height'])) {
                return null;
            }
            $urls[] = ['url' => self::absolute((string) MediaUrl::resolve($copy['path'])), 'width' => (int) $copy['width'], 'height' => (int) $copy['height']];
        }

        $largest = end($urls);

        return [
            'src' => $largest['url'],
            'srcset' => implode(', ', array_map(fn (array $copy) => "{$copy['url']} {$copy['width']}w", $urls)),
            'width' => $largest['width'],
            'height' => $largest['height'],
        ];
    }

    /**
     * A browser on another origin (the storefront) needs the whole address, whatever the disk's URL setting is.
     */
    private static function absolute(string $url): string
    {
        return Str::startsWith($url, '/') ? url($url) : $url;
    }

    /**
     * The `homepage.images` block of the site-settings API. An empty alt means the picture is decoration: it is never
     * filled in from a headline.
     *
     * @return array<string, mixed>
     */
    public static function payload(SiteSetting $settings): array
    {
        $alt = fn (?string $text): string => trim((string) $text);

        return [
            'hero' => [
                'desktop' => self::set($settings, 'hero_image'),
                'mobile' => self::set($settings, 'hero_image_mobile'),
                'alt' => $alt($settings->hero_image_alt),
            ],
            'hot_deals_tile' => [
                'image' => self::set($settings, 'hot_deals_tile_image'),
                'alt' => $alt($settings->hot_deals_tile_image_alt),
            ],
            'new_arrivals_tile' => [
                'image' => self::set($settings, 'new_arrivals_tile_image'),
                'alt' => $alt($settings->new_arrivals_tile_image_alt),
            ],
        ];
    }

    // ---- The admin's heads-up

    /**
     * A calm warning when a fresh upload is smaller than recommended or far from the recommended shape. The picture is
     * still accepted: this is a heads-up, never a block.
     */
    public static function uploadWarning(string $field): \Closure
    {
        [$recommendedWidth, $recommendedHeight] = self::PICTURES[$field]['recommended'];
        $label = self::PICTURES[$field]['label'];

        return function ($state) use ($recommendedWidth, $recommendedHeight, $label): void {
            if (! $state instanceof TemporaryUploadedFile) {
                return;
            }

            $size = @getimagesize($state->getRealPath());
            if (! $size) {
                return;
            }

            [$width, $height] = $size;
            $problems = [];

            if ($width < $recommendedWidth) {
                $problems[] = "narrower than the recommended {$recommendedWidth} px";
            }

            $target = $recommendedWidth / $recommendedHeight;
            if (abs(($width / max($height, 1)) - $target) / $target > 0.15) {
                $problems[] = "a different shape from the recommended {$recommendedWidth} x {$recommendedHeight}";
            }

            if ($problems !== []) {
                Notification::make()
                    ->warning()
                    ->title('Picture size heads-up')
                    ->body("This {$label} is {$width} x {$height} px: ".implode(' and ', $problems).'. It has still been uploaded; this is only a heads-up. A small picture can look soft, and the part outside the panel is cut off from the centre.')
                    ->send();
            }
        };
    }
}
