<?php

namespace App\Support\Courier;

/**
 * Text on its way to a courier, made safe on our side first. Steadfast cuts over-long values and replaces the characters
 * { } ; < > $ with a space on its own, so we do the same: the parcel carries what we meant to send, not what was left.
 */
final class CourierText
{
    public static function clean(?string $value, int $max): string
    {
        $text = str_replace(['{', '}', ';', '<', '>', '$'], ' ', (string) $value);
        $text = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text) ?? $text;
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

        return mb_strlen($text) > $max ? rtrim(mb_substr($text, 0, $max)) : $text;
    }
}
