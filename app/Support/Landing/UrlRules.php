<?php

namespace App\Support\Landing;

/**
 * Which addresses are acceptable in author HTML and CSS.
 */
final class UrlRules
{
    /** Hosts an embedded video may come from. */
    public const EMBED_HOSTS = ['www.youtube.com', 'youtube.com', 'www.youtube-nocookie.com', 'youtube-nocookie.com', 'player.vimeo.com'];

    /**
     * A web address, a mail or phone link, an in-page anchor or a path on this site. Never a script, and never a data
     * address unless it is a small embedded picture.
     */
    public static function safeUrl(string $url, bool $imagesOnlyData = false): bool
    {
        $url = self::normalise($url);

        if ($url === '') {
            return true;
        }

        if (preg_match('/^data:image\/(png|jpe?g|gif|webp|avif);base64,/i', $url)) {
            return true;
        }

        if (preg_match('/^[a-z][a-z0-9+.\-]*:/i', $url)) {
            if (preg_match('/^https?:/i', $url)) {
                return true;
            }

            // Mail, phone and chat links are fine as links, but make no sense as a picture or background.
            return ! $imagesOnlyData && (bool) preg_match('/^(mailto|tel|sms|whatsapp):/i', $url);
        }

        // Relative, anchor, query or protocol-relative.
        return true;
    }

    /** The address with the tricks scripts hide behind (control characters, spaces, entities already decoded) removed. */
    public static function normalise(string $url): string
    {
        $url = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/[\x00-\x20\x7f]+/', '', $url) ?? '');
    }

    public static function isRelative(string $url): bool
    {
        $url = self::normalise($url);

        return $url !== ''
            && ! str_starts_with($url, '#')
            && ! str_starts_with($url, '//')
            && ! str_starts_with($url, '/')
            && ! preg_match('/^[a-z][a-z0-9+.\-]*:/i', $url);
    }

    /** An embed address that may be framed, rewritten to the privacy-friendly YouTube host; null when it may not. */
    public static function embedUrl(string $url): ?string
    {
        $url = self::normalise($url);

        if (str_starts_with($url, '//')) {
            $url = 'https:'.$url;
        }

        $parts = parse_url($url);

        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || ! in_array(strtolower((string) ($parts['host'] ?? '')), self::EMBED_HOSTS, true)) {
            return null;
        }

        $host = strtolower((string) $parts['host']);

        if (str_contains($host, 'youtube.com')) {
            $host = 'www.youtube-nocookie.com';
        }

        return 'https://'.$host.($parts['path'] ?? '').(isset($parts['query']) ? '?'.$parts['query'] : '');
    }
}
