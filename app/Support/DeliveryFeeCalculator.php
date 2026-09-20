<?php

namespace App\Support;

/**
 * Bangladesh delivery fee rule, per the published Shipping Policy: free
 * inside Dhaka on orders over ৳2,000, a flat ৳80 fee inside Dhaka
 * otherwise, and a flat ৳130 fee for every other division.
 */
class DeliveryFeeCalculator
{
    private const FREE_DELIVERY_THRESHOLD = 2000;

    private const DHAKA_FEE = 80;

    private const OUTSIDE_DHAKA_FEE = 130;

    public static function forDivision(string $division, int $subtotal): int
    {
        $isDhaka = strcasecmp(trim($division), 'Dhaka') === 0;

        if (! $isDhaka) {
            return self::OUTSIDE_DHAKA_FEE;
        }

        return $subtotal > self::FREE_DELIVERY_THRESHOLD ? 0 : self::DHAKA_FEE;
    }
}
