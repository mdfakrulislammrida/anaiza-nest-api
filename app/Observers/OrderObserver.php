<?php

namespace App\Observers;

use App\Models\Order;
use App\Support\Courier\CourierBooking;
use App\Support\Email\TransactionalEmails;
use Illuminate\Support\Facades\Log;

/**
 * Watches for an order's status changing, wherever that happens (the order page, a bulk action, anything else).
 */
class OrderObserver
{
    public function updated(Order $order): void
    {
        if ($order->wasChanged('status')) {
            // The day it was delivered is what review requests count from. Kept the first time: moving an order away from
            // Delivered and back does not start the days again.
            if ($order->status === 'delivered' && $order->delivered_at === null) {
                $order->forceFill(['delivered_at' => now()])->saveQuietly();
            }

            TransactionalEmails::orderStatusChanged($order);

            // Automatic booking, only when the owner has switched it on (Settings > Courier), and only as an order reaches Processing.
            // After the response, so a slow courier never slows the page; it refuses a risky or unready order on its own.
            if ($order->status === 'processing') {
                dispatch(function () use ($order): void {
                    try {
                        CourierBooking::autoBook($order);
                    } catch (\Throwable $e) {
                        Log::error('Automatic courier booking failed.', ['order_id' => $order->id, 'error' => get_class($e)]);
                    }
                })->afterResponse();
            }
        }
    }
}
