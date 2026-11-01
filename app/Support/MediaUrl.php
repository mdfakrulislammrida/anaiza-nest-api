<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
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

    /** A picture on another server, fetched once for the invoice with short timeouts. Anything that goes wrong is just no picture (the shop name is printed instead). */
    private static function fetchForPdf(string $url): ?string
    {
        try {
            $response = Http::connectTimeout(3)->timeout(5)->get($url);
            $mime = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));

            if (! $response->successful() || ! in_array($mime, ['image/png', 'image/jpeg', 'image/gif'], true) || strlen($response->body()) > 1_500_000) {
                return null;
            }

            return 'data:'.$mime.';base64,'.base64_encode($response->body());
        } catch (\Throwable) {
            return null;
        }
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

        $disk = Storage::disk('public');

        if (Str::startsWith($value, ['http://', 'https://'])) {
            // This server's own picture, written as its web address (https://api.../storage/branding/logo.png): read from the disk, no network.
            $path = (string) parse_url($value, PHP_URL_PATH);

            if (Str::startsWith($path, '/storage/') && $disk->exists(Str::after($path, '/storage/'))) {
                $value = rawurldecode(Str::after($path, '/storage/'));
            } else {
                return self::fetchForPdf($value);
            }
        }

        if (! $disk->exists($value)) {
            return null;
        }

        $mime = $disk->mimeType($value) ?: 'image/png';

        return 'data:'.$mime.';base64,'.base64_encode((string) $disk->get($value));
    }
}
