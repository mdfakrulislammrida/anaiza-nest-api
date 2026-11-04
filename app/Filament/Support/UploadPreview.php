<?php

namespace App\Filament\Support;

use Filament\Forms\Components\BaseFileUpload;
use Filament\Forms\Components\FileUpload;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use League\Flysystem\UnableToCheckFileExistence;

/**
 * How every upload field of the admin shows a picture that is already stored: a small thumbnail (96 px), drawn from the page's own address, with a neutral
 * tile when the file is gone. Given to every FileUpload in AdminPanelProvider::boot, so a new field gets it without being listed anywhere.
 *
 * What it changes is only the address the PREVIEW is loaded from (and its size). Where a file is saved, its name, the upload, delete and reorder rules and the
 * image-size rules are exactly as each field says.
 */
final class UploadPreview
{
    /** The thumbnail's height in the field, in pixels (the CSS in upload-styles.blade.php makes the tile 96 wide). */
    public const SIZE = 96;

    public static function configure(BaseFileUpload $field): void
    {
        if ($field instanceof FileUpload) {
            $field->imagePreviewHeight((string) self::SIZE);
        }

        // Filament drops a stored file that is not on the disk when the form loads, so a missing picture left an empty field and the next save quietly
        // erased the value. It is kept here instead (the tile below stands in for it, and the person can remove it): what is stored stays what it was.
        $field->afterStateHydrated(static function (BaseFileUpload $component, string|array|null $state): void {
            if (blank($state)) {
                $component->state([]);

                return;
            }

            $component->state(
                collect(Arr::wrap($state))
                    ->filter(static fn ($file): bool => is_string($file) && filled($file))
                    ->mapWithKeys(static fn (string $file): array => [(string) Str::uuid() => $file])
                    ->all(),
            );
        });

        $field->getUploadedFileUsing(static function (BaseFileUpload $component, string $file, string|array|null $storedFileNames): ?array {
            /** @var FilesystemAdapter $storage */
            $storage = $component->getDisk();
            $name = ($component->isMultiple() ? ($storedFileNames[$file] ?? null) : $storedFileNames) ?? basename($file);

            // A picture typed in as a full address (older or seeded rows), or a file this server no longer has: the neutral tile, still removable.
            if (Str::startsWith($file, ['http://', 'https://'])) {
                return ['name' => $name, 'size' => 0, 'type' => 'image/png', 'url' => self::placeholderUrl($name)];
            }

            try {
                $exists = $storage->exists($file);
            } catch (UnableToCheckFileExistence) {
                $exists = false;
            }

            if (! $exists) {
                return ['name' => $name, 'size' => 0, 'type' => 'image/png', 'url' => self::placeholderUrl($name)];
            }

            return [
                'name' => $name,
                'size' => $storage->size($file),
                'type' => $storage->mimeType($file),
                'url' => $component->getDiskName() === 'public' ? self::url($file) : $storage->url($file),
            ];
        });
    }

    /** The page's own address for a stored picture (relative, so it is the same host and scheme as the admin page). */
    public static function url(string $path): string
    {
        return '/admin/upload-preview/'.implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    /** The neutral tile; the address ends in the missing file's name so the field still shows it. */
    public static function placeholderUrl(string $name): string
    {
        return '/admin/upload-placeholder/'.rawurlencode($name !== '' ? $name : 'missing');
    }
}
