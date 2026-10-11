<?php

namespace App\Support\Recovery;

use App\Support\Contact;

/**
 * The one-click unsubscribe link in every cart and review email. It is signed with the application key and carries the
 * email and phone it is for, so opening it needs no login and nothing to look up.
 */
final class Unsubscribe
{
    public static function token(?string $email, ?string $phone): string
    {
        $payload = rtrim(strtr(base64_encode(json_encode(['e' => Contact::email($email), 'p' => Contact::phone($phone)], JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');

        return $payload.'.'.self::sign($payload);
    }

    public static function url(?string $email, ?string $phone): string
    {
        return rtrim((string) config('app.url'), '/').'/unsubscribe/'.self::token($email, $phone);
    }

    /** @return array{email: ?string, phone: ?string}|null */
    public static function resolve(?string $token): ?array
    {
        if (! is_string($token) || substr_count($token, '.') !== 1) {
            return null;
        }

        [$payload, $signature] = explode('.', $token);

        if (! hash_equals(self::sign($payload), $signature)) {
            return null;
        }

        $data = json_decode((string) base64_decode(strtr($payload, '-_', '+/'), true), true);

        if (! is_array($data)) {
            return null;
        }

        return ['email' => $data['e'] ?? null, 'phone' => $data['p'] ?? null];
    }

    private static function sign(string $payload): string
    {
        return substr(hash_hmac('sha256', 'unsubscribe:'.$payload, (string) config('app.key')), 0, 32);
    }
}
