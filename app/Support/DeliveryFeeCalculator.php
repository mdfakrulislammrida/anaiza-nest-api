<?php

namespace App\Support;

use App\Models\SiteSetting;

/**
 * Bangladesh delivery fee rule, read from the editable Site Settings:
 * free inside Dhaka on orders over the free-delivery threshold, a flat
 * Dhaka fee below it, and a flat fee for every other division.
 *
 * With nothing edited the settings equal the rules that used to be hardcoded
 * here (free over ৳2,000, ৳80 inside Dhaka, ৳130 elsewhere) -- the model
 * supplies those as its defaults even when no settings row exists yet.
 */
class DeliveryFeeCalculator
{
    public static function forDivision(string $division, int $subtotal): int
    {
        $settings = SiteSetting::query()->first() ?? new SiteSetting;

        $isDhaka = strcasecmp(trim($division), 'Dhaka') === 0;

        if (! $isDhaka) {
            return $settings->delivery_fee_outside_dhaka;
        }

        return $subtotal > $settings->free_delivery_threshold ? 0 : $settings->delivery_fee_dhaka;
    }
}
