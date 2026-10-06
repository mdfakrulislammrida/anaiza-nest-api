<?php

namespace App\Support;

use App\Jobs\SendMetaConversionEvent;
use App\Jobs\SendTikTokConversionEvent;
use App\Models\CookieConsentSetting;
use App\Models\Order;

/**
 * When a sale is reported to Meta and TikTok (server side).
 *
 * Cash on delivery counts at placement: it is an order the shop will fulfil. A wallet order (bKash, Nagad,
 * Rocket) is only a sale once the shop has confirmed the money arrived, so nothing is sent at placement and the
 * sale is sent when an admin marks the payment verified. A payment marked failed is never sent.
 *
 * In opt-in cookie mode a visitor who did not allow marketing cookies is never sent, at either moment.
 */
final class ConversionEvents
{
    /** Meta and TikTok do not accept an event older than this many days. */
    public const MAX_EVENT_AGE_DAYS = 7;

    /**
     * Whether this order's visitor may be reported to ad platforms.
     */
    public static function allowed(bool $marketingConsent): bool
    {
        return ! CookieConsentSetting::current()->requiresOptIn() || $marketingConsent;
    }

    /**
     * Cash on delivery: queued at placement, as it has always been.
     */
    public static function sendAtPlacement(Order $order, ?string $ip, ?string $userAgent): void
    {
        if (! self::allowed((bool) $order->marketing_consent)) {
            return;
        }

        $order->forceFill(['conversion_sent_at' => now()])->saveQuietly();

        SendMetaConversionEvent::dispatch($order, $ip, $userAgent);
        SendTikTokConversionEvent::dispatch($order, $ip, $userAgent);
    }

    /**
     * Wallet: sent when the payment is verified. Runs after the admin's response (no queue worker needed).
     * Safe to call more than once: the first call claims the order atomically and every later one does nothing.
     *
     * @return bool whether the sale was sent
     */
    public static function sendOnVerification(Order $order): bool
    {
        $ip = $order->client_ip;
        $userAgent = $order->client_user_agent;

        // The visitor's address and browser were only kept for this moment.
        Order::query()->whereKey($order->id)->update(['client_ip' => null, 'client_user_agent' => null]);
        $order->forceFill(['client_ip' => null, 'client_user_agent' => null])->syncOriginal();

        if (! self::allowed((bool) $order->marketing_consent)) {
            return false;
        }

        // The claim: only one caller gets to flip this from null, however many times the payment is verified.
        $claimed = Order::query()->whereKey($order->id)->whereNull('conversion_sent_at')->update(['conversion_sent_at' => now()]);

        if ($claimed !== 1) {
            return false;
        }

        $order->refresh();
        $eventTime = self::eventTime($order);

        SendMetaConversionEvent::dispatchAfterResponse($order, $ip, $userAgent, $eventTime);
        SendTikTokConversionEvent::dispatchAfterResponse($order, $ip, $userAgent, $eventTime);

        return true;
    }

    /**
     * The time the sale is reported as having happened: when the order was placed, or now if that is too long ago
     * for the platforms to accept.
     */
    public static function eventTime(Order $order): int
    {
        $placed = $order->created_at;

        return $placed !== null && $placed->greaterThan(now()->subDays(self::MAX_EVENT_AGE_DAYS))
            ? $placed->timestamp
            : now()->timestamp;
    }
}
