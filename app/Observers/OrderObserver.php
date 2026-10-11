<?php

namespace App\Observers;

use App\Models\Order;
use App\Support\Email\TransactionalEmails;

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
        }
    }
}
