<?php

namespace App\Support\Feeds;

/**
 * Plain text for product feeds. The ad platforms want text, not HTML, and they reject some characters: so tags are
 * stripped, entities decoded, runs of white space collapsed, control characters and emoji removed, and the result cut to the
 * platform's length limit.
 */
final class FeedText
{
    public static function plain(?string $html): string
    {
        $text = (string) $html;
        // A closing block tag or <br> is a break in the text, not nothing: "<p>One</p><p>Two</p>" must not become "OneTwo".
        $text = preg_replace('#</(p|div|li|h[1-6]|tr|td|th)>|<br\s*/?>#i', ' ', $text) ?? $text;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Control characters, then emoji and other pictographs (TikTok rejects them; none of the three needs them).
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? $text;
        $text = preg_replace('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE0F}\x{200D}]/u', '', $text) ?? $text;

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    /**
     * A title: plain text with the double quotes taken out, cut to the limit.
     */
    public static function title(?string $value, int $max): string
    {
        return self::limit(trim(str_replace(['"', '“', '”', '„'], '', self::plain($value))), $max);
    }

    public static function description(?string $value, int $max): string
    {
        return self::limit(self::plain($value), $max);
    }

    public static function limit(string $text, int $max): string
    {
        return mb_strlen($text) <= $max ? $text : rtrim(mb_substr($text, 0, $max));
    }
}
