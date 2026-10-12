<?php

namespace App\Support;

use App\Models\Customer;
use Illuminate\Support\Carbon;

/**
 * The link in a "choose a new password" email.
 *
 * It is signed with the application key and carries the customer and its own end time (60 minutes), so no table is
 * needed. It is also tied to the customer's current password: the moment the password changes (including by using the
 * link), the signature stops matching, so a link works once and an old one can never undo a newer password.
 *
 *   {frontend}/reset-password?token={base64url("{customerId}.{expiresAtUnix}")}.{signature}
 */
final class PasswordReset
{
    public const VALID_MINUTES = 60;

    public static function token(Customer $customer, ?Carbon $now = null): string
    {
        $now ??= now();
        $payload = rtrim(strtr(base64_encode($customer->id.'.'.$now->copy()->addMinutes(self::VALID_MINUTES)->getTimestamp()), '+/', '-_'), '=');

        return $payload.'.'.self::sign($payload, $customer);
    }

    public static function url(Customer $customer, ?Carbon $now = null): string
    {
        return rtrim((string) config('app.frontend_url'), '/').'/reset-password?token='.self::token($customer, $now);
    }

    /**
     * The customer a token is for: null when it is malformed, forged, past its time, or the password has changed since.
     */
    public static function resolve(?string $token, ?Carbon $now = null): ?Customer
    {
        $now ??= now();

        if (! is_string($token) || substr_count($token, '.') !== 1) {
            return null;
        }

        [$payload, $signature] = explode('.', $token);
        $decoded = base64_decode(strtr($payload, '-_', '+/'), true);

        if ($decoded === false || ! preg_match('/^(\d+)\.(\d+)$/', $decoded, $parts)) {
            return null;
        }

        if ((int) $parts[2] < $now->getTimestamp()) {
            return null;
        }

        $customer = Customer::query()->find((int) $parts[1]);

        if ($customer === null || ! hash_equals(self::sign($payload, $customer), $signature)) {
            return null;
        }

        return $customer;
    }

    /** "an hour", for the email. */
    public static function expiresInWords(): string
    {
        return self::VALID_MINUTES === 60 ? 'one hour' : self::VALID_MINUTES.' minutes';
    }

    private static function sign(string $payload, Customer $customer): string
    {
        // The password's own fingerprint: when it changes, every link made before stops working.
        $fingerprint = substr(hash('sha256', (string) $customer->password), 0, 24);

        return substr(hash_hmac('sha256', 'password-reset:'.$payload.':'.$fingerprint, (string) config('app.key')), 0, 40);
    }
}
