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
    public static function email(?string $email): ?string
    {
        if (! $email) {
            return null;
        }

        return hash('sha256', strtolower(trim($email)));
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
}
