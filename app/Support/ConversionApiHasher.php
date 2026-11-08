<?php

namespace App\Support;

/**
 * Normalizes and SHA-256-hashes customer identifiers the way Meta's
 * Conversions API and TikTok's Events API both require for user_data
 * matching -- see Meta's "Hashing and Normalizing" docs and TikTok's
 * Events API "Advanced Matching" docs, which specify the same rules.
 */
class ConversionApiHasher
{
    /** What a form or a script writes when it has no value. Meta must never receive the hash of one of these as if it were a person's detail. */
    private const PLACEHOLDERS = ['', 'null', 'undefined', 'none', 'n/a', 'na', 'nan', '-'];

    /** True for nothing, whitespace, or a literal placeholder such as "null" or "N/A" (case-insensitive, after trimming). */
    public static function isPlaceholder(?string $value): bool
    {
        return in_array(strtolower(trim((string) $value)), self::PLACEHOLDERS, true);
    }

    public static function email(?string $email): ?string
    {
        if (self::isPlaceholder($email)) {
            return null;
        }

        return hash('sha256', strtolower(trim((string) $email)));
    }

    /**
     * Digits only, with the country code included and no leading zero or
     * '+' -- customer phone numbers here are stored as local Bangladeshi
     * numbers (e.g. "01711223344"), so a leading 0 is replaced with the
     * country code 880.
     */
    public static function phone(?string $phone): ?string
    {
        if (! $phone) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $phone);

        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, '0')) {
            $digits = '880'.substr($digits, 1);
        } elseif (! str_starts_with($digits, '880')) {
            $digits = '880'.$digits;
        }

        return hash('sha256', $digits);
    }

    /**
     * Lower case, letters only (no spaces, punctuation or digits), then hashed: Meta's rule for names, cities and states.
     * Bangla letters are kept. The browser applies the same rule (see userData.ts), so both sides hash to the same value.
     */
    public static function text(?string $value): ?string
    {
        if (self::isPlaceholder($value)) {
            return null;
        }

        $clean = preg_replace('/[^\p{L}\p{M}]/u', '', mb_strtolower(trim((string) $value)));

        return $clean === null || $clean === '' ? null : hash('sha256', $clean);
    }

    /**
     * The first word of a full name and everything after it, each hashed. A one-word name has no last name.
     *
     * @return array{first: ?string, last: ?string}
     */
    public static function fullName(?string $name): array
    {
        $parts = preg_split('/\s+/u', trim((string) $name), 2) ?: [];

        return ['first' => self::text($parts[0] ?? null), 'last' => self::text($parts[1] ?? null)];
    }

    public static function country(?string $code = 'bd'): ?string
    {
        return self::isPlaceholder($code) ? null : hash('sha256', strtolower(trim((string) $code)));
    }
}
