<?php

namespace App\Support\Courier;

use App\Mail\ShopAlertMail;
use App\Models\CourierEvent;
use App\Models\CourierStatusMap;
use App\Models\Order;
use App\Support\Dhaka;
use App\Support\Email\TransactionalEmails;
use App\Support\System\ShopAlerts;
use App\Support\System\SystemEvents;
use Illuminate\Support\Facades\Mail;

/**
 * The notes the courier work sends to the shop's own address (Site settings > Shop alerts): a parcel that came back, and the
 * morning note of bookings that failed or have gone quiet. Nothing here changes an order, and nothing reaches a customer.
 */
final class CourierAlerts
{
    /** A booked parcel with no news for this many days is listed in the morning note. */
    public const QUIET_DAYS = 3;

    /** A parcel a courier returned, cancelled or only partly delivered. Told once per order. */
    public static function parcelProblem(Order $order, string $courier, CourierStatusMap $map): void
    {
        $already = CourierEvent::query()->where('order_id', $order->id)->where('event_type', 'admin_notified')->exists();
        $to = ShopAlerts::recipient();

        if ($already || $to === null) {
            return;
        }

        CourierEvent::query()->create([
            'order_id' => $order->id, 'courier' => $courier, 'source' => 'system', 'event_type' => 'admin_notified',
            'status' => $order->courier_status, 'summary' => 'The owner was told: '.$map->stage.'.',
        ]);

        $name = CourierSettingNames::label($courier);
        $mail = new ShopAlertMail(
            "Order #{$order->id}: {$map->stage} ({$name})",
            [
                "{$name} reports order #{$order->id}: {$map->stage}.",
                '',
                'Nothing was changed for you: stock is not put back and no refund is made. Those are yours to decide.',
            ],
            ['Open the order' => url('/admin/orders/'.$order->id.'/edit')],
        );

        TransactionalEmails::afterResponse(fn () => Mail::to($to)->send($mail), 'Courier parcel note', ['order_id' => $order->id]);
    }

    /**
     * The morning note: bookings that failed, and booked parcels with no news for three days. Sent only when there is
     * something to say. Returns how many lines it had.
     */
    public static function digest(): int
    {
        $failed = Order::query()
            ->whereNull('courier_booked_at')
            ->whereNotNull('courier_last_error')
            ->where('courier_last_error_at', '>=', now()->subDays(7))
            ->whereNotIn('status', ['cancelled', 'delivered'])
            ->orderBy('id')->limit(40)->get();

        $quiet = Order::query()
            ->whereNotNull('courier_booked_at')
            ->whereNull('courier_final_at')
            ->whereNotIn('status', ['cancelled', 'delivered'])
            ->where(fn ($query) => $query
                ->where('courier_synced_at', '<', now()->subDays(self::QUIET_DAYS))
                ->orWhere(fn ($inner) => $inner->whereNull('courier_synced_at')->where('courier_booked_at', '<', now()->subDays(self::QUIET_DAYS))))
            ->orderBy('id')->limit(40)->get();

        $count = $failed->count() + $quiet->count();
        $to = ShopAlerts::recipient();

        if ($count === 0 || $to === null) {
            return $count;
        }

        $lines = ['Courier bookings that need a look.', ''];

        if ($failed->isNotEmpty()) {
            $lines[] = 'Could not be booked:';
            foreach ($failed as $order) {
                $lines[] = "  #{$order->id}: ".($order->courier_last_error ?: 'no reason given');
            }
            $lines[] = '';
        }

        if ($quiet->isNotEmpty()) {
            $lines[] = 'Booked, but no news from the courier for '.self::QUIET_DAYS.' days:';
            foreach ($quiet as $order) {
                $lines[] = "  #{$order->id}: ".CourierSettingNames::label($order->courier).', last news '.($order->courier_synced_at ? Dhaka::readable($order->courier_synced_at) : 'never').'.';
            }
        }

        Mail::to($to)->send(new ShopAlertMail('Courier bookings that need a look ('.$count.')', $lines, ['Open the orders' => url('/admin/orders')]));
        SystemEvents::mark('courier_digest', $count.' lines sent');

        return $count;
    }
}
