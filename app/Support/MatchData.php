<?php

namespace App\Support;

/**
 * The three things the storefront may pass with an order to help Meta recognise the buyer: the _fbp cookie, the _fbc cookie, and a hashed
 * external id. Tracking data must never fail an order, so each cleaner returns the value when it has exactly the right shape and null for
 * anything else (a wrong shape, an array, an over-long text). Nothing here hashes: the external id arrives already hashed and is sent as it is.
 */
final class MatchData
{
    /** fb.<subdomain index>.<creation time in ms>.<random number> */
    public static function fbp(mixed $value): ?string
    {
        return self::match($value, '/^fb\.[0-2]\.\d{10,13}\.\d{1,20}$/', 100);
    }

    /** fb.<subdomain index>.<creation time in ms>.<the fbclid of the ad click> */
    public static function fbc(mixed $value): ?string
    {
        return self::match($value, '/^fb\.[0-2]\.\d{10,13}\.[A-Za-z0-9_-]{1,200}$/', 255);
    }

    /** SHA-256 in lower-case hex: 64 characters. A raw email, a phone number or an upper-case hash is not accepted. */
    public static function externalId(mixed $value): ?string
    {
        return self::match($value, '/^[a-f0-9]{64}$/', 64);
    }

    private static function match(mixed $value, string $pattern, int $max): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $text = trim($value);

        return strlen($text) <= $max && preg_match($pattern, $text) === 1 ? $text : null;
    }
}
