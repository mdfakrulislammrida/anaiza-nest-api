<?php

namespace App\Support\Reviews;

use App\Models\OrderItem;
use App\Models\ProductReview;
use Illuminate\Support\Carbon;

/**
 * The link that lets a buyer review a piece without typing an order number and phone number. It is signed with the
 * application key and carries its own end date (60 days after the request first went out), so nothing needs storing;
 * it names one order line, and a line can only be reviewed once, so the link is used up by the review it produces.
 *
 *   {frontend}/product/{slug}?review={token}#reviews
 */
final class ReviewLinks
{
    public const VALID_DAYS = 60;

    /** @return string the token: base64url("{orderItemId}.{expiresAtUnix}") . "." . signature */
    public static function make(OrderItem $item, ?Carbon $now = null): string
    {
        $now ??= now();
        $anchor = $item->order?->review_request_sent_at ?? $now;
        $expires = Carbon::instance($anchor)->addDays(self::VALID_DAYS)->getTimestamp();
        $payload = rtrim(strtr(base64_encode($item->id.'.'.$expires), '+/', '-_'), '=');

        return $payload.'.'.self::sign($payload);
    }

    /** The full link for one order line, or null when the piece is no longer in the catalogue. */
    public static function url(OrderItem $item, ?Carbon $now = null): ?string
    {
        $slug = $item->product?->slug;

        if (blank($slug)) {
            return null;
        }

        return rtrim((string) config('app.frontend_url'), '/').'/product/'.$slug.'?review='.self::make($item, $now).'#reviews';
    }

    /**
     * The order line a token stands for: null when it is malformed, forged or past its date.
     */
    public static function resolve(?string $token, ?Carbon $now = null): ?OrderItem
    {
        $now ??= now();

        if (! is_string($token) || substr_count($token, '.') !== 1) {
            return null;
        }

        [$payload, $signature] = explode('.', $token);

        if (! hash_equals(self::sign($payload), $signature)) {
            return null;
        }

        $decoded = base64_decode(strtr($payload, '-_', '+/'), true);

        if ($decoded === false || ! preg_match('/^(\d+)\.(\d+)$/', $decoded, $parts)) {
            return null;
        }

        if ((int) $parts[2] < $now->getTimestamp()) {
            return null;
        }

        return OrderItem::query()->with(['order.customer', 'product'])->find((int) $parts[1]);
    }

    /** Whether the line has already been reviewed (the link is then spent). */
    public static function isUsed(OrderItem $item): bool
    {
        return ProductReview::query()->where('order_item_id', $item->id)->exists();
    }

    private static function sign(string $payload): string
    {
        return substr(hash_hmac('sha256', 'review-link:'.$payload, (string) config('app.key')), 0, 32);
    }
}
