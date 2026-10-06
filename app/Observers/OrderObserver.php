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
            TransactionalEmails::orderStatusChanged($order);
        }
    }
}
