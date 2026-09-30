<?php

namespace App\Support;

use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;

/**
 * Thin wrapper around Intervention Image's decode/encode so every observer
 * that converts an upload to WebP shares one driver setup and one quality
 * default, instead of each re-instantiating ImageManager/WebpEncoder itself.
 */
class WebpImage
{
    public static function decode(string $absolutePath): ImageInterface
    {
        return (new ImageManager(new Driver))->decodePath($absolutePath);
    }

    public static function encode(ImageInterface $image, int $quality = 82): string
    {
        return (string) $image->encode(new WebpEncoder(quality: $quality));
    }
}
