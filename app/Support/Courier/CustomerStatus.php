<?php

namespace App\Support\Courier;

use App\Models\Order;

/**
 * What the Track order page says about the parcel, in plain words. It never repeats a courier's own status text or a
 * rider's unconfirmed word, and says nothing at all until the order has been booked.
 */
final class CustomerStatus
{
    public static function text(Order $order): ?string
    {
        if ($order->courier_booked_at === null || $order->status === 'cancelled') {
            return null;
        }

        $courier = CourierSettingNames::label($order->courier);

        return match (true) {
            $order->status === 'delivered' => 'Delivered.',
            $order->courier_returned_at !== null => "{$courier} could not complete this delivery. We will contact you.",
            str_contains(strtolower((string) $order->courier_status), 'hold') => "On hold with {$courier}. We are following it up.",
            $order->courier_status === null || $order->courier_status === '' => "Handed to {$courier}.",
            default => "With {$courier}, on its way to you.",
        };
    }
}
