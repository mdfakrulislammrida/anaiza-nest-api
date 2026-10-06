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

    /**
     * An image for the invoice PDF: a file on the public disk is embedded (so the PDF needs no network access and never
     * shows a broken image), anything else is passed through as its URL.
     */
    public static function forPdf(?string $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        if (Str::startsWith($value, ['http://', 'https://'])) {
            return $value;
        }

        $disk = Storage::disk('public');

        if (! $disk->exists($value)) {
            return null;
        }

        $mime = $disk->mimeType($value) ?: 'image/png';

        return 'data:'.$mime.';base64,'.base64_encode((string) $disk->get($value));
    }
}
