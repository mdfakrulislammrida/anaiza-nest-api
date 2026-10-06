<?php

namespace App\Support;

/**
 * Rules and wording for paying by bKash, Nagad or Rocket with a manual check: the customer sends money
 * to the shop's number and types in the number they paid from and the transaction ID, and the shop
 * confirms it in the admin. There is no gateway and no callback.
 */
final class WalletPayments
{
    public const METHODS = ['bkash', 'nagad', 'rocket'];

    public const LABELS = ['bkash' => 'bKash', 'nagad' => 'Nagad', 'rocket' => 'Rocket'];

    public const AWAITING = 'awaiting_verification';

    public const STATUSES = [
        'cod' => 'Cash on delivery',
        self::AWAITING => 'Awaiting verification',
        'verified' => 'Verified',
        'failed' => 'Failed',
    ];

    public static function isWallet(?string $method): bool
    {
        return in_array($method, self::METHODS, true);
    }

    /**
     * The status a new order starts with: a wallet order waits for a person to check it.
     */
    public static function initialStatus(?string $method): string
    {
        return $method === 'cod' ? 'cod' : self::AWAITING;
    }

    /**
     * A Bangladeshi mobile number as 01XXXXXXXXX, whatever way it was typed (spaces, dashes, +88, 88).
     * Anything that does not look like one is returned trimmed, for validation to refuse.
     */
    public static function normalizeNumber(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $digits = preg_replace('/[\s\-().]/', '', trim($value)) ?? '';

        return preg_replace('/^(?:\+?88)(?=01)/', '', $digits) ?? $digits;
    }

    public static function isValidNumber(?string $value): bool
    {
        return (bool) preg_match('/^01[3-9]\d{8}$/', (string) $value);
    }

    /**
     * Letters and digits only, kept in upper case so one ID can never be stored twice in different cases.
     */
    public static function normalizeTrxId(?string $value): ?string
    {
        return $value === null ? null : mb_strtoupper(trim($value));
    }

    /**
     * The sentence a customer is told about their payment, in plain words. Null when there is nothing to
     * say (cash on delivery).
     */
    public static function message(?string $status): ?string
    {
        return match ($status) {
            self::AWAITING => 'We are checking your payment, we will confirm it shortly.',
            'verified' => 'Payment received.',
            'failed' => 'We could not match this payment, please contact us.',
            default => null,
        };
    }

    public static function defaultInstructions(string $method): string
    {
        $app = self::LABELS[$method] ?? 'wallet';

        return "<ol><li>Open the {$app} app and choose Send Money.</li>"
            .'<li>Send {{amount}} to {{number}}.</li>'
            ."<li>Copy the transaction ID from the {$app} confirmation message.</li>"
            .'<li>Enter the number you paid from and the transaction ID below, then place your order.</li></ol>';
    }
}
