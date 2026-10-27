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
     * Cash on delivery: sent at placement, right after the checkout response. (It used to be queued, but production has
     * no queue worker, so those jobs were never run.)
     */
    public static function sendAtPlacement(Order $order, ?string $ip, ?string $userAgent): void
    {
        if ($order->isLegacy()) {
            return;
        }

        if (! self::allowed((bool) $order->marketing_consent)) {
            return;
        }

        $order->forceFill(['conversion_sent_at' => now()])->saveQuietly();

        SendMetaConversionEvent::dispatchAfterResponse($order, $ip, $userAgent);
        SendTikTokConversionEvent::dispatchAfterResponse($order, $ip, $userAgent);
    }

    /**
     * Wallet: sent when the payment is verified. Runs after the admin's response (no queue worker needed).
     * Safe to call more than once: the first call claims the order atomically and every later one does nothing.
     *
     * @return bool whether the sale was sent
     */
    public static function sendOnVerification(Order $order): bool
    {
        if ($order->isLegacy()) {
            return false;
        }

        // Nothing will be sent for a visitor who may not be reported: the details kept for this moment are not needed.
        if (! self::allowed((bool) $order->marketing_consent)) {
            self::eraseVisitorDetails($order);

            return false;
        }

        // The claim: only one caller gets to flip this from null, however many times the payment is verified.
        $claimed = Order::query()->whereKey($order->id)->whereNull('conversion_sent_at')->update(['conversion_sent_at' => now()]);

        if ($claimed !== 1) {
            return false;
        }

        // The jobs read the visitor's address, browser, fbp and external id from the order itself, and the order forgets them when the sale has
        // been sent (see settle()), not before: a send that fails is tried again with the same details.
        $order->refresh();
        $eventTime = self::eventTime($order);

        SendMetaConversionEvent::dispatchAfterResponse($order, $order->client_ip, $order->client_user_agent, $eventTime);
        SendTikTokConversionEvent::dispatchAfterResponse($order, $order->client_ip, $order->client_user_agent, $eventTime);

        return true;
    }

    /**
     * Called when a platform's send has ended. The visitor's address, browser, fbp and external id are forgotten as soon as every platform is done and none
     * of them failed (sent, or not meant to be sent). While a send has failed they stay, for the automatic tries and for Send again, until
     * eraseStale() forgets them 24 hours after the first try. fbc is a click id, like fbclid, and is kept.
     */
    public static function settle(Order $order): void
    {
        foreach (array_keys(ConversionAttempts::PLATFORMS) as $platform) {
            if (in_array(ConversionAttempts::state($order, $platform)['state'], ['none', 'retrying', 'failed'], true)) {
                return;
            }
        }

        self::eraseVisitorDetails($order);
    }

    /** Forgets what was kept about the visitor for the send: address, browser, fbp, external id. */
    public static function eraseVisitorDetails(Order $order): void
    {
        $blank = ['client_ip' => null, 'client_user_agent' => null, 'fbp' => null, 'external_id' => null];

        Order::query()->whereKey($order->id)->update($blank);
        $order->forceFill($blank)->syncOriginal();
    }

    /** The 24-hour limit: details of a sale first sent more than a day ago are forgotten whatever happened to it. @return int orders cleaned */
    public static function eraseStale(): int
    {
        return Order::query()
            ->whereNotNull('conversion_sent_at')->where('conversion_sent_at', '<', now()->subHours(ConversionAttempts::WINDOW_HOURS))
            ->where(fn ($q) => $q->whereNotNull('client_ip')->orWhereNotNull('client_user_agent')->orWhereNotNull('fbp')->orWhereNotNull('external_id'))
            ->update(['client_ip' => null, 'client_user_agent' => null, 'fbp' => null, 'external_id' => null]);
    }

    /**
     * What a purchase is worth to Meta and TikTok: the items total after any discount, without the delivery fee (the order's total minus its
     * delivery fee, never negative). The browser and the server container send the same number, so every sender agrees.
     */
    public static function purchaseValue(Order $order): float
    {
        return (float) max(0, (int) $order->total - (int) $order->delivery_fee);
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
