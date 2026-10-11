<?php

namespace App\Observers;

use App\Models\Banner;
use App\Support\WebpImage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Runs a slide's pictures through the WebP pipeline, the way category banners are.
 *
 * The desktop image (made for 1920x800) is converted to WebP and capped at 1920px wide, with 1280 and 640px copies for the
 * browser to choose from. The mobile image is converted and capped at 900px wide when the admin uploads one; when they do not, a
 * mobile crop (750x500, 3:2) is made from the middle of the desktop image, and made again whenever the desktop image changes. An
 * admin's own mobile upload is never overwritten. A conversion that fails keeps the original upload and is logged: it never
 * blocks saving the slide.
 */
class BannerObserver
{
    private const DESKTOP_WIDTH = 1920;

    private const MEDIUM_WIDTH = 1280;

    private const SMALL_WIDTH = 640;

    private const MOBILE_MAX_WIDTH = 900;

    private const MOBILE_AUTO_WIDTH = 750;

    private const MOBILE_AUTO_HEIGHT = 500;

    private const DIRECTORY = 'banners/generated';

    public function saving(Banner $banner): void
    {
        try {
            $disk = Storage::disk('public');

            if ($this->isLocalUpload($banner, 'image_url')) {
                $this->convertDesktop($banner, $disk);
            }

            $explicitMobile = $this->isLocalUpload($banner, 'mobile_image');
            if ($explicitMobile) {
                $this->convertMobile($banner, $disk);
                $banner->mobile_auto_generated = false;
            }

            $mobileCleared = $banner->isDirty('mobile_image') && blank($banner->mobile_image);
            $desktopChangedAndMobileIsOurs = $banner->isDirty('image_url') && ($banner->mobile_auto_generated || blank($banner->mobile_image));

            if (! $explicitMobile && ($mobileCleared || $desktopChangedAndMobileIsOurs)) {
                $this->generateMobile($banner, $disk);
            }
        } catch (\Throwable $e) {
            Log::error('Banner image conversion failed.', ['banner_id' => $banner->id, 'error' => $e->getMessage()]);
        }
    }

    private function isLocalUpload(Banner $banner, string $field): bool
    {
        return $banner->isDirty($field) && filled($banner->{$field}) && ! Str::startsWith($banner->{$field}, ['http://', 'https://'])
            && ! Str::startsWith($banner->{$field}, self::DIRECTORY.'/');
    }

    private function convertDesktop(Banner $banner, $disk): void
    {
        $original = $banner->image_url;

        if (! $disk->exists($original)) {
            return;
        }

        $source = WebpImage::decode($disk->path($original));
        $disk->makeDirectory(self::DIRECTORY);
        $name = (string) Str::uuid();
        $paths = [];

        foreach ([self::DESKTOP_WIDTH => 'image_url', self::MEDIUM_WIDTH => 'image_1280', self::SMALL_WIDTH => 'image_640'] as $width => $field) {
            // A source narrower than a size does not get a copy of it: upscaling only adds bytes. The largest always exists.
            if ($source->width() < $width && $width !== self::DESKTOP_WIDTH) {
                $paths[$field] = null;

                continue;
            }

            $copy = clone $source;
            if ($copy->width() > $width) {
                $copy->scaleDown(width: $width);
            }

            $path = self::DIRECTORY."/{$name}-{$width}.webp";
            $disk->put($path, WebpImage::encode($copy));
            $paths[$field] = $path;

            if ($field === 'image_url') {
                $banner->image_width = $copy->width();
                $banner->image_height = $copy->height();
            }
        }

        $banner->image_url = $paths['image_url'];
        $banner->image_1280 = $paths['image_1280'];
        $banner->image_640 = $paths['image_640'];
        $disk->delete($original);
    }

    private function convertMobile(Banner $banner, $disk): void
    {
        $original = $banner->mobile_image;

        if (! $disk->exists($original)) {
            return;
        }

        $source = WebpImage::decode($disk->path($original));
        if ($source->width() > self::MOBILE_MAX_WIDTH) {
            $source->scaleDown(width: self::MOBILE_MAX_WIDTH);
        }

        $disk->makeDirectory(self::DIRECTORY);
        $path = self::DIRECTORY.'/'.Str::uuid().'-mobile.webp';
        $disk->put($path, WebpImage::encode($source));

        $banner->mobile_image = $path;
        $banner->mobile_width = $source->width();
        $banner->mobile_height = $source->height();
        $disk->delete($original);
    }

    private function generateMobile(Banner $banner, $disk): void
    {
        if (blank($banner->image_url) || Str::startsWith($banner->image_url, ['http://', 'https://']) || ! $disk->exists($banner->image_url)) {
            $banner->mobile_image = null;
            $banner->mobile_width = null;
            $banner->mobile_height = null;
            $banner->mobile_auto_generated = false;

            return;
        }

        $source = WebpImage::decode($disk->path($banner->image_url));
        $source->cover(self::MOBILE_AUTO_WIDTH, self::MOBILE_AUTO_HEIGHT);

        $disk->makeDirectory(self::DIRECTORY);
        $path = self::DIRECTORY.'/'.Str::uuid().'-mobile-auto.webp';
        $disk->put($path, WebpImage::encode($source));

        $banner->mobile_image = $path;
        $banner->mobile_width = self::MOBILE_AUTO_WIDTH;
        $banner->mobile_height = self::MOBILE_AUTO_HEIGHT;
        $banner->mobile_auto_generated = true;
    }
}
