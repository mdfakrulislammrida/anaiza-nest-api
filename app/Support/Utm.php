<?php

namespace App\Support;

/**
 * A campaign text (utm_source, utm_medium, utm_campaign, utm_content, utm_term) as one stable name, so the same campaign is never stored two ways.
 *
 * The storefront decodes the address once and sends clean text, but a link encoded twice (an ad platform's own encoding on top of the address's) reaches
 * the shop as "AN+|+SALES+|+Broad" (the plus signs are spaces) or as "AN%20%7C%20SALES" when some other client sends it raw. This is the same rule as the storefront's
 * (cleanUtmValue in src/lib/attribution.ts):
 *   - a value with no space in it but plus signs between its words (two or more, or a plus around a separator such as |) has those plus signs read as spaces;
 *   - percent-encoding is decoded ONCE, only when the value really holds a %XX sequence (so "100% off" stays as it is) and only when the result is valid text;
 *   - the plus rule is applied again to what the decoding gave (a %2B), without decoding again;
 *   - spaces are trimmed and doubled spaces made single.
 * One plus ("Buy1+Get1"), a plus at the end ("C++") and a value that already has spaces are left alone. An empty result is null.
 */
final class Utm
{
    public const FIELDS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'];

    public static function clean(?string $value): ?string
    {
        $text = self::plusesAsSpaces(trim((string) $value));

        if (preg_match('/%[0-9A-Fa-f]{2}/', $text) === 1) {
            $decoded = rawurldecode($text);

            if (mb_check_encoding($decoded, 'UTF-8')) {
                $text = self::plusesAsSpaces($decoded);
            }
        }

        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

        return $text === '' ? null : $text;
    }

    private static function plusesAsSpaces(string $text): string
    {
        if (preg_match('/\s/u', $text) === 1 || ! str_contains($text, '+')) {
            return $text;
        }

        $between = preg_match_all('/(?<=\S)\+(?=\S)/u', $text);

        return $between >= 2 || preg_match('/\+[|:\/\x{2013}-]\+/u', $text) === 1 ? str_replace('+', ' ', $text) : $text;
    }
}
