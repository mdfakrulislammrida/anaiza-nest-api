<?php

namespace App\Support;

/**
 * The one place a wa.me link is built: digits only for the number, the message URL-encoded. Everything that opens a chat
 * (the admin's review and recovery actions, and the same rule in the storefront's whatsapp.ts) goes through this shape.
 */
final class WhatsApp
{
    /**
     * Digits only, with the country code. A local Bangladeshi number (01XXXXXXXXX) gets 880 in front.
     */
    public static function digits(?string $number): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $number) ?? '';

        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        } elseif (str_starts_with($digits, '0')) {
            $digits = '880'.substr($digits, 1);
        }

        return strlen($digits) >= 8 ? $digits : null;
    }

    /**
     * A wa.me link to a number, with the message ready in the box. Null when there is no usable number.
     */
    public static function link(?string $number, ?string $message = null): ?string
    {
        $digits = self::digits($number);

        if ($digits === null) {
            return null;
        }

        $message = trim((string) $message);

        return 'https://wa.me/'.$digits.($message === '' ? '' : '?text='.rawurlencode($message));
    }

    /**
     * Whether a number is acceptable for the shop's WhatsApp setting: international format, + and 8 to 15 digits.
     */
    public static function isInternational(?string $number): bool
    {
        return (bool) preg_match('/^\+[1-9]\d{7,14}$/', (string) $number);
    }

    /** Fills {name}-style placeholders in a WhatsApp message. */
    public static function fill(string $template, array $values): string
    {
        $pairs = [];
        foreach ($values as $key => $value) {
            $pairs['{'.$key.'}'] = (string) $value;
        }

        return trim(strtr($template, $pairs));
    }
}
