<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Resolves an image field's stored value to a URL a browser can load.
 *
 * Admin-managed image fields hold either a path on the "public" disk
 * (uploaded via a Filament FileUpload field) or, for older/seeded rows, a
 * full external URL entered by hand. This makes both work everywhere the
 * API exposes an image field, without callers needing to know which case
 * they're in.
 */
class MediaUrl
{
    public static function resolve(?string $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        if (Str::startsWith($value, ['http://', 'https://'])) {
            return $value;
        }

        return Storage::disk('public')->url($value);
    }
}
