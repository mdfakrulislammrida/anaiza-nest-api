<?php

namespace App\Support\Email;

use App\Mail\OrderConfirmationMail;
use App\Mail\TransactionalMail;
use App\Models\Customer;
use App\Models\Order;
use App\Models\SiteSetting;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Sends the transactional emails without a queue worker, and without ever being able to break what triggered them.
 *
 * Production has no queue worker, so nothing here is queued: each email is sent right after the HTTP response has
 * been delivered (dispatch()->afterResponse()), a failure is logged and goes no further, and a customer with no
 * email address simply gets none.
 */
final class TransactionalEmails
{
    /**
     * Runs $send once the response is on its way. Anything it throws is logged, never raised.
     *
     * @param  array<string, mixed>  $context
     */
    public static function afterResponse(Closure $send, string $what, array $context = []): void
    {
        dispatch(function () use ($send, $what, $context): void {
            try {
                $send();
            } catch (\Throwable $e) {
                Log::error("{$what} failed.", $context + ['error' => $e->getMessage()]);
            }
        })->afterResponse();
    }

    public static function orderConfirmation(Order $order): void
    {
        $order->loadMissing('customer');

        if (blank($order->customer?->email)) {
            return;
        }

        $email = $order->customer->email;
        $site = SiteSetting::query()->first();

        self::afterResponse(
            fn () => Mail::to($email)->send(new OrderConfirmationMail($order->loadMissing(['customer', 'items']), $site)),
            'Order confirmation email',
            ['order_id' => $order->id],
        );
    }

    /**
     * An order has just changed status. Shipped, delivered and cancelled each send their email, once per order:
     * what has been sent is recorded on the order, so moving the status back and forth never repeats one.
     */
    public static function orderStatusChanged(Order $order): void
    {
        $template = TemplateDefinitions::STATUS_TEMPLATES[$order->status] ?? null;
        $order->loadMissing('customer');

        if ($template === null || blank($order->customer?->email)) {
            return;
        }

        $status = $order->status;

        // The claim: whoever records the status first sends; any other attempt finds it recorded and stops.
        $claimed = DB::transaction(function () use ($order, $status): bool {
            $row = Order::query()->lockForUpdate()->find($order->id);
            $sent = $row?->emails_sent ?? [];

            if ($row === null || isset($sent[$status])) {
                return false;
            }

            $sent[$status] = now()->toIso8601String();
            $row->forceFill(['emails_sent' => $sent])->saveQuietly();
            $order->forceFill(['emails_sent' => $sent])->syncOriginal();

            return true;
        });

        if (! $claimed) {
            return;
        }

        $email = $order->customer->email;
        $site = SiteSetting::query()->first();

        self::afterResponse(function () use ($order, $template, $email, $site, $status): void {
            try {
                Mail::to($email)->send(new TransactionalMail($template, $order->load(['customer', 'items.product']), $order->customer, $site));
            } catch (\Throwable $e) {
                // Not sent, so it is not recorded as sent: the next change to this status can try again.
                $row = Order::query()->find($order->id);
                $sent = $row?->emails_sent ?? [];
                unset($sent[$status]);
                $row?->forceFill(['emails_sent' => $sent ?: null])->saveQuietly();

                throw $e;
            }
        }, "Order {$status} email", ['order_id' => $order->id]);
    }

    public static function welcome(Customer $customer): void
    {
        if (blank($customer->email)) {
            return;
        }

        $site = SiteSetting::query()->first();

        self::afterResponse(
            fn () => Mail::to($customer->email)->send(new TransactionalMail(TemplateDefinitions::WELCOME, null, $customer, $site)),
            'Welcome email',
            ['customer_id' => $customer->id],
        );
    }
}
