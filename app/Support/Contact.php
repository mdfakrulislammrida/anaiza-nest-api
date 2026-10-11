<?php

namespace App\Support;

/**
 * Email addresses and Bangladeshi mobile numbers, in one canonical form, so "the same person" can be recognised across
 * a cart, an order, a coupon and an unsubscribe however the number or address was typed.
 */
final class Contact
{
    /** An email as stored and compared: trimmed, lower case. Null when it is not a usable address. */
    public static function email(?string $value): ?string
    {
        $value = mb_strtolower(trim((string) $value));

        return $value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : null;
    }

    /** A mobile number as 01XXXXXXXXX (spaces, dashes, +88 and 88 dropped). Null when it is not a valid one. */
    public static function phone(?string $value): ?string
    {
        $number = WalletPayments::normalizeNumber($value);

        return WalletPayments::isValidNumber($number) ? $number : null;
    }

    public static function samePhone(?string $a, ?string $b): bool
    {
        $a = self::phone($a);

        return $a !== null && $a === self::phone($b);
    }
}
