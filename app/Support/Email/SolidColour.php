<?php

namespace App\Support\Email;

/**
 * Makes sure no table or cell in an email's body is left transparent. A mail app that darkens an email decides for itself what
 * "no colour" becomes, and that is how a logo or a line of text ends up unreadable. Every table, cell and header cell without a colour
 * of its own gets the card's ivory and the navy text; the dark-mode rules in the layout then turn those two into the dark card and
 * ivory text (the "em-card" and "em-text" classes). Cells that already have a colour (a linen tile, a panel) are left alone.
 */
final class SolidColour
{
    public const CARD = '#F6F1E7';

    public const TEXT = '#1B2A41';

    public static function apply(string $html): string
    {
        return (string) preg_replace_callback('/<(table|td|th)(\s[^>]*)?>/i', function (array $match): string {
            $tag = strtolower($match[1]);
            $attributes = $match[2] ?? '';

            if (! str_contains(strtolower($attributes), 'bgcolor=')) {
                $attributes = self::addClass($attributes, 'em-card');
                $attributes .= ' bgcolor="'.self::CARD.'"';
                $attributes = self::addStyle($attributes, 'background-color:'.self::CARD);
            }

            if ($tag !== 'table' && ! preg_match('/style="[^"]*(?<![-a-z])color:/i', $attributes)) {
                $attributes = self::addClass($attributes, 'em-text');
                $attributes = self::addStyle($attributes, 'color:'.self::TEXT);
            }

            return '<'.$match[1].$attributes.'>';
        }, $html);
    }

    private static function addClass(string $attributes, string $class): string
    {
        if (preg_match('/class="([^"]*)"/i', $attributes)) {
            return (string) preg_replace('/class="([^"]*)"/i', 'class="$1 '.$class.'"', $attributes, 1);
        }

        return $attributes.' class="'.$class.'"';
    }

    private static function addStyle(string $attributes, string $declaration): string
    {
        if (preg_match('/style="([^"]*)"/i', $attributes)) {
            return (string) preg_replace_callback('/style="([^"]*)"/i', fn (array $m): string => 'style="'.rtrim($m[1], '; ').';'.$declaration.';"', $attributes, 1);
        }

        return $attributes.' style="'.$declaration.';"';
    }
}
