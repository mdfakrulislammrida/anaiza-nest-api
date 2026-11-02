<?php

namespace App\Support;

use App\Models\SiteSetting;
use Illuminate\Support\Facades\Log;

/**
 * Bangladesh delivery fee rule, read from the editable Site Settings: a flat fee inside Dhaka CITY, free there on
 * orders over the free-delivery threshold, and a flat (higher) fee for everywhere else, Gazipur, Narayanganj, Savar
 * and the rest of the Dhaka division included. What counts as the city is decided in DhakaCity (config/delivery.php).
 *
 * Only new orders go through here; an order keeps the fee it was created with.
 */
class DeliveryFeeCalculator
{
    /** The fee for this address and cart subtotal. Logs a Dhaka-district address whose thana is missing or unknown. */
    public static function forAddress(?string $division, ?string $district, ?string $thana, int $subtotal): int
    {
        $quote = self::quote($division, $district, $thana, $subtotal);

        if (DhakaCity::isDoubtful($quote['reason'])) {
            Log::warning('Delivery fee: Dhaka district without a recognised thana, charged the outside fee.', [
                'division' => $division,
                'district' => $district,
                'thana' => $thana,
                'reason' => $quote['reason'],
            ]);
        }

        return $quote['fee'];
    }

    /**
     * The full answer for the checkout: zone, fee, whether it is free, and the delivery time.
     *
     * @return array{zone: string, inside_dhaka_city: bool, reason: string, fee: int, free_delivery: bool, free_delivery_over: int, standard_fee: int, delivery_days: array{min: int, max: int}}
     */
    public static function quote(?string $division, ?string $district, ?string $thana, int $subtotal): array
    {
        $settings = SiteSetting::query()->first() ?? new SiteSetting;

        ['inside' => $inside, 'reason' => $reason] = DhakaCity::classify($division, $district, $thana);

        $free = $inside && $subtotal > $settings->free_delivery_threshold;
        $standard = $inside ? $settings->delivery_fee_dhaka : $settings->delivery_fee_outside_dhaka;

        return [
            'zone' => $inside ? DhakaCity::INSIDE : DhakaCity::OUTSIDE,
            'inside_dhaka_city' => $inside,
            'reason' => $reason,
            'fee' => $free ? 0 : $standard,
            'free_delivery' => $free,
            'free_delivery_over' => $settings->free_delivery_threshold,
            'standard_fee' => $standard,
            'delivery_days' => $inside
                ? ['min' => $settings->delivery_days_dhaka_min, 'max' => $settings->delivery_days_dhaka_max]
                : ['min' => $settings->delivery_days_outside_min, 'max' => $settings->delivery_days_outside_max],
        ];
    }
}
