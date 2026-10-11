<?php

namespace App\Support;

/**
 * Keeps secrets out of logs. An access token is never written to the log: if a platform's reply or an exception message ever
 * contains one, it is replaced before the text is stored.
 */
final class Redact
{
    /**
     * @param  array<int, string|null>  $secrets
     */
    public static function secrets(string $text, array $secrets): string
    {
        foreach (array_filter($secrets, fn ($secret) => is_string($secret) && strlen($secret) >= 8) as $secret) {
            $text = str_replace($secret, '[redacted]', $text);
        }

        return $text;
    }
}
