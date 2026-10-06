<?php

namespace App\Support;

/**
 * The brand kit's writing rules as a check: words the kit leaves out, shouting, exclamation marks and
 * emoji. It only ever reports. Nothing here blocks saving; the admin shows the notes under a field and
 * counts them in the save notification.
 *
 * Source: docs/brand-kit-extract.md in the storefront ("Words we leave out", "Emoji", "Writing rules").
 */
final class BrandVoice
{
    /**
     * Words and phrases the kit leaves out. Matched as whole words, any case, with an optional plural.
     */
    public const BANNED = [
        'best quality',
        'luxury premium',
        'exclusive',
        'elite',
        'cheap',
        'hurry',
        'grab now',
        'limited stock',
        'world class',
        'world-class',
        'amazing',
        'must-have',
        'must have',
        'hot deal',
        'wow',
    ];

    /**
     * Capitalised words that are fine as they are (acronyms people really write that way).
     */
    public const CAPS_ALLOWED = ['SMS', 'COD', 'USB', 'UK', 'BDT', 'SKU', 'FAQ', 'PDF', 'URL'];

    /**
     * The only emoji the kit uses, at most three per caption, never in a headline.
     */
    public const EMOJI_ALLOWED = ['🫖', '✨', '🎁', '🤍', '🚚'];

    public const EMOJI_MAX = 3;

    /**
     * Pictographs: the emoji blocks, dingbats, and a few symbols that render as emoji. Variation
     * selectors and joiners are left out on purpose so a flag or a skin tone counts as one.
     */
    private const EMOJI_PATTERN = '/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B50}\x{2B55}\x{2B1B}\x{2B1C}\x{231A}\x{231B}\x{23E9}-\x{23F3}\x{23F8}-\x{23FA}\x{2764}\x{203C}\x{2049}]/u';

    /**
     * Plain-language notes about the text, empty when it reads in the kit's voice. Accepts HTML.
     *
     * @return list<string>
     */
    public static function notes(?string $text): array
    {
        $plain = self::plain($text);

        if ($plain === '') {
            return [];
        }

        $notes = [];
        $lower = mb_strtolower($plain);

        foreach (self::BANNED as $phrase) {
            if (preg_match('/(?<![\p{L}\p{N}])'.preg_quote($phrase, '/').'s?(?![\p{L}\p{N}])/u', $lower)) {
                $notes[] = "\"{$phrase}\" is a word the kit leaves out.";
            }
        }

        if (preg_match_all('/\b[A-Z]{3,}\b/', $plain, $matches)) {
            $shouting = array_values(array_diff(array_unique($matches[0]), self::CAPS_ALLOWED));
            foreach ($shouting as $word) {
                $notes[] = "\"{$word}\" is in capitals. The kit writes in sentence case.";
            }
        }

        $exclamations = substr_count($plain, '!');
        if ($exclamations > 0) {
            $notes[] = $exclamations === 1
                ? 'There is an exclamation mark. The kit does not shout.'
                : "There are {$exclamations} exclamation marks. The kit does not shout.";
        }

        preg_match_all(self::EMOJI_PATTERN, $plain, $emoji);
        $found = $emoji[0];

        if (count($found) > self::EMOJI_MAX) {
            $notes[] = count($found).' emoji. The kit allows at most '.self::EMOJI_MAX.'.';
        }

        $outside = array_values(array_unique(array_diff($found, self::EMOJI_ALLOWED)));
        if ($outside !== []) {
            $notes[] = 'Emoji outside the kit set ('.implode(' ', self::EMOJI_ALLOWED).'): '.implode(' ', $outside).'.';
        }

        return $notes;
    }

    /**
     * How many notes a set of texts raises in total (for the save notification).
     *
     * @param  iterable<int|string, string|null>  $texts
     */
    public static function count(iterable $texts): int
    {
        $total = 0;

        foreach ($texts as $text) {
            $total += count(self::notes($text));
        }

        return $total;
    }

    /**
     * "Brand check: 2 notes", or null when there is nothing to say.
     *
     * @param  iterable<int|string, string|null>  $texts
     */
    public static function summary(iterable $texts): ?string
    {
        $count = self::count($texts);

        if ($count === 0) {
            return null;
        }

        return 'Brand check: '.$count.($count === 1 ? ' note' : ' notes');
    }

    /**
     * Visible text only: tags dropped, entities decoded, whitespace collapsed.
     */
    private static function plain(?string $text): string
    {
        if ($text === null || trim($text) === '') {
            return '';
        }

        // Keep a space where a tag was, so "</p><p>" does not glue two words together.
        $plain = html_entity_decode(strip_tags(str_replace('<', ' <', $text)), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s+/u', ' ', $plain) ?? '');
    }
}
