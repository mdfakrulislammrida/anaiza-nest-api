<?php

namespace App\Support\Courier;

use App\Models\BlockedContact;
use App\Models\CourierEvent;
use App\Models\CourierSetting;
use App\Models\Order;
use App\Models\User;
use App\Support\Contact;
use App\Support\System\SystemEvents;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Sending an order to a courier. Everything that decides what is sent is computed here on the server from the order itself:
 * a number or a name that came from the browser is never used.
 *
 * An order is booked once. A second click finds the booking and shows it; a failed booking leaves the order unbooked with the
 * reason on it and a way to try again. Nothing is half saved: the order is only marked booked after the courier has answered.
 */
final class CourierBooking
{
    /** A bulk run stops starting new bookings after this many seconds (shared hosting has a time limit); the rest can be sent again. */
    public const BULK_SECONDS = 25;

    public const BULK_MAX = 50;

    /** How long a claim on an order (a booking under way) holds before another attempt may take it. */
    private const CLAIM_SECONDS = 180;

    /**
     * What a courier is owed on delivery: the whole total for cash on delivery, nothing for a payment already verified, and
     * null while the payment is undecided or failed (the order is not ready to book).
     */
    public static function codAmount(Order $order): ?int
    {
        return match ($order->payment_status) {
            'cod' => max(0, (int) $order->total),
            'verified' => 0,
            default => null,
        };
    }

    /**
     * Reasons to be careful with an order. Used to block automatic booking, and shown as a warning on a manual one.
     *
     * @return list<string>
     */
    public static function riskReasons(Order $order): array
    {
        $reasons = [];
        $to = $order->recipient();

        $block = BlockedContact::matching([
            'phone' => Contact::phone((string) $to['phone']),
            'email' => $to['email'],
            'address' => $to['address'],
        ]);

        if ($block !== null) {
            $reasons[] = 'The phone, email or address matches a blocked buyer.';
        }

        return $reasons;
    }

    /**
     * The exact data that would be sent, and what stops it.
     *
     * @return array{courier: string, fields: array<string, string>, payload: array<string, mixed>, blocks: list<string>, warnings: list<string>, cod: ?int}
     */
    public static function preview(Order $order, string $courier, ?CourierSetting $settings = null): array
    {
        $settings ??= CourierSetting::current();
        $order->loadMissing(['customer', 'items']);
        $to = $order->recipient();
        $blocks = [];

        if ($order->isLegacy()) {
            $blocks[] = 'The order was imported from the old site. It is history and is never sent to a courier.';
        }

        if (! isset(CourierSetting::COURIERS[$courier])) {
            $blocks[] = 'Choose Steadfast or Pathao.';
        } elseif (! $settings->ready($courier)) {
            $blocks[] = CourierSetting::COURIERS[$courier].' is not switched on, or its details are not saved (Settings > Courier).';
        }

        if (in_array($order->status, ['delivered', 'cancelled'], true)) {
            $blocks[] = 'The order is '.$order->status.'.';
        }

        $phone = Contact::phone((string) $to['phone']);
        if ($phone === null) {
            $blocks[] = 'The phone number is missing or is not a Bangladeshi mobile number.';
        }

        $addressLine = implode(', ', array_filter([trim((string) $to['address']), trim((string) $to['thana']), trim((string) $to['district'])]));
        if (trim((string) $to['address']) === '') {
            $blocks[] = 'The street address is missing.';
        }

        $name = CourierText::clean($to['name'], 100);
        if ($name === '') {
            $blocks[] = 'The recipient name is missing.';
        }

        $cod = self::codAmount($order);
        if ($cod === null) {
            $blocks[] = $order->payment_status === 'failed'
                ? 'The payment failed.'
                : 'The payment has not been verified yet. Verify it first, then send the order.';
        }

        $note = CourierText::clean($order->gift_note, 480);
        $items = $order->items->map(fn ($item) => trim($item->product_name.($item->quantity > 1 ? ' x'.$item->quantity : '')))->filter()->implode(', ');
        $weight = min(10.0, max(0.5, (float) $settings->default_weight));
        $invoice = CourierText::clean(($settings->invoice_prefix ?? '').$order->id, 100);

        if ($courier === CourierSetting::PATHAO) {
            $address = CourierText::clean($addressLine, 220);
            if (mb_strlen($address) < 10) {
                $blocks[] = 'Pathao needs an address of at least 10 characters.';
            }
            if ($name !== '' && mb_strlen($name) < 3) {
                $blocks[] = 'Pathao needs a recipient name of at least 3 letters.';
            }

            $payload = [
                'store_id' => (int) $settings->pathao_store_id,
                'merchant_order_id' => $invoice,
                'recipient_name' => $name,
                'recipient_phone' => $phone,
                'recipient_address' => $address,
                'delivery_type' => (int) $settings->pathao_delivery_type,
                'item_type' => (int) $settings->pathao_item_type,
                'item_quantity' => max(1, (int) $order->items->sum('quantity')),
                'item_weight' => $weight,
                'item_description' => CourierText::clean($items, 255) ?: null,
                'special_instruction' => $note ?: null,
                'amount_to_collect' => $cod ?? 0,
            ];
        } else {
            $address = CourierText::clean($addressLine, 490);
            $payload = [
                'invoice' => $invoice,
                'recipient_name' => $name,
                'recipient_phone' => $phone,
                'recipient_address' => $address,
                'cod_amount' => $cod ?? 0,
                'note' => $note ?: null,
                'item_description' => CourierText::clean($items, 255) ?: null,
            ];
        }

        $warnings = self::riskReasons($order);

        return [
            'courier' => $courier,
            'fields' => array_filter([
                'Courier' => CourierSetting::COURIERS[$courier] ?? $courier,
                'Order' => '#'.$order->id.' (sent as '.$invoice.')',
                'Recipient' => $name,
                'Phone' => $phone ?? '—',
                'Address' => $address,
                'Cash to collect' => $cod === null ? '—' : '৳'.number_format($cod),
                'Weight' => number_format($weight, 1).' kg',
                'Note' => $note,
            ], fn ($value) => $value !== '' && $value !== null),
            'payload' => array_filter($payload, fn ($value) => $value !== null),
            'blocks' => $blocks,
            'warnings' => $warnings,
            'cod' => $cod,
        ];
    }

    /**
     * Book one order.
     *
     * @return array{ok: bool, message: string}
     */
    public static function book(Order $order, string $courier, ?User $by = null, bool $markShipped = false, string $source = 'admin'): array
    {
        if ($by !== null && ! $by->hasPermission('orders.send_to_courier')) {
            return ['ok' => false, 'message' => 'You do not have permission to send orders to a courier.'];
        }

        $order->refresh();

        if ($order->isBooked() || $order->courier_booked_at !== null) {
            return ['ok' => false, 'message' => 'Already booked with '.($order->courier_name ?: $order->courier).' (consignment '.($order->courier_consignment_id ?: 'id not received').'). It is not sent again.'];
        }

        $settings = CourierSetting::current();
        $plan = self::preview($order, $courier, $settings);

        if ($plan['blocks'] !== []) {
            return ['ok' => false, 'message' => implode(' ', $plan['blocks'])];
        }

        // The claim: one booking at a time per order. A second click while the first is under way finds it taken.
        $claimed = Order::query()
            ->whereKey($order->id)
            ->whereNull('courier_booked_at')
            ->where(fn ($query) => $query->whereNull('courier_booking_started_at')->orWhere('courier_booking_started_at', '<', now()->subSeconds(self::CLAIM_SECONDS)))
            ->update(['courier_booking_started_at' => now()]);

        if ($claimed === 0) {
            return ['ok' => false, 'message' => 'This order is being booked just now, or has been. Reload the page.'];
        }

        $consignment = null;
        $trackingCode = null;
        $initial = null;
        $note = null;

        try {
            if ($courier === CourierSetting::STEADFAST) {
                try {
                    $made = SteadfastClient::forButton($settings)->createOrder($plan['payload']);
                    $consignment = $made['consignment_id'];
                    $trackingCode = $made['tracking_code'];
                    $initial = $made['status'];
                } catch (CourierException $e) {
                    // The invoice is already at Steadfast: an earlier try got through (a lost answer). It is booked; its id never reached us.
                    if ($e->kind !== CourierException::DUPLICATE) {
                        throw $e;
                    }
                    $note = 'Steadfast already had this invoice, so the parcel is booked, but its consignment id never reached us. Open it in the Steadfast panel.';
                }
            } else {
                $made = PathaoClient::forButton($settings)->createOrder($plan['payload']);
                $consignment = $made['consignment_id'];
                $trackingCode = $made['consignment_id'];
                $initial = $made['status'];
            }
        } catch (CourierException $e) {
            self::recordFailure($order, $courier, $e->getMessage(), $by, $source);

            return ['ok' => false, 'message' => $e->getMessage()];
        } catch (\Throwable $e) {
            Log::error('Courier booking failed.', ['order_id' => $order->id, 'courier' => $courier, 'error' => get_class($e)]);
            self::recordFailure($order, $courier, 'Something went wrong while booking. Nothing was saved; try again.', $by, $source);

            return ['ok' => false, 'message' => 'Something went wrong while booking. Nothing was saved; try again.'];
        }

        DB::transaction(function () use ($order, $courier, $plan, $settings, $consignment, $trackingCode, $initial, $by, $source, $note): void {
            $name = CourierSetting::COURIERS[$courier];
            $order->forceFill([
                'courier' => $courier,
                'courier_name' => $name,
                'courier_invoice' => $plan['payload']['invoice'] ?? $plan['payload']['merchant_order_id'] ?? (string) $order->id,
                'courier_consignment_id' => $consignment,
                'courier_tracking_code' => $trackingCode,
                'tracking_number' => $trackingCode ?: $order->tracking_number,
                'courier_tracking_url' => $settings->trackingUrl($courier, $trackingCode),
                'courier_booked_at' => now(),
                'courier_booked_by' => $by?->id,
                'courier_booking_started_at' => null,
                'cod_amount_sent' => $plan['cod'],
                'courier_status' => $initial,
                'courier_status_text' => $note ?? null,
                'courier_next_sync_at' => now()->addMinutes(15),
                'courier_sync_failures' => 0,
                'courier_last_error' => null,
                'courier_last_error_at' => null,
            ])->saveQuietly();

            CourierEvent::query()->create([
                'order_id' => $order->id,
                'courier' => $courier,
                'source' => 'booking',
                'event_type' => 'booked',
                'status' => $initial,
                'user_id' => $by?->id,
                'summary' => 'Booked with '.$name.($consignment ? ', consignment '.$consignment : '').', cash to collect ৳'.number_format((int) $plan['cod']).($by ? ', by '.$by->name : ' (automatic)').'.',
                // What was sent, without the recipient's phone or address.
                'payload' => ['source' => $source, 'invoice' => $order->courier_invoice, 'consignment_id' => $consignment, 'tracking_code' => $trackingCode, 'cod_amount' => $plan['cod'], 'weight' => $plan['payload']['item_weight'] ?? null],
            ]);
        });

        SystemEvents::mark('courier_'.$courier.'_booking', 'Order #'.$order->id.' booked.');

        if ($markShipped) {
            $fresh = Order::query()->find($order->id);

            // Saved through the model, so the "Order shipped" email goes out once, as it does from the order page.
            if ($fresh !== null && in_array($fresh->status, ['pending', 'processing'], true)) {
                $fresh->update(['status' => 'shipped']);
            }
        }

        return ['ok' => true, 'message' => 'Booked with '.CourierSetting::COURIERS[$courier].($consignment ? ' (consignment '.$consignment.').' : '. '.$note)];
    }

    /**
     * Book many, one result for each. Each is its own request to the courier, so one refusal never spoils the others; the run
     * stops starting new ones when the time budget is used, and says so for the rest.
     *
     * @param  Collection<int, Order>  $orders
     * @return list<array{order_id: int, ok: bool, message: string}>
     */
    public static function bulk(Collection $orders, string $courier, ?User $by, bool $markShipped = false): array
    {
        $started = microtime(true);
        $results = [];

        foreach ($orders->take(self::BULK_MAX) as $order) {
            if (microtime(true) - $started > self::BULK_SECONDS) {
                $results[] = ['order_id' => $order->id, 'ok' => false, 'message' => 'Not sent: the time limit for one batch was reached. Select it again and send.'];

                continue;
            }

            $results[] = ['order_id' => $order->id] + self::book($order, $courier, $by, $markShipped, 'bulk');
        }

        foreach ($orders->skip(self::BULK_MAX) as $order) {
            $results[] = ['order_id' => $order->id, 'ok' => false, 'message' => 'Not sent: at most '.self::BULK_MAX.' orders at a time.'];
        }

        return $results;
    }

    /**
     * The automatic booking: when an order reaches Processing and the owner has switched it on. Never for an order with a
     * reason to be careful, an unverified wallet order, or one with a missing phone or address; those wait for a person.
     */
    public static function autoBook(Order $order): void
    {
        $settings = CourierSetting::current();

        if ($order->isLegacy() || ! $settings->auto_book || $order->courier_booked_at !== null) {
            return;
        }

        $courier = $settings->defaultCourier();

        if ($courier === null) {
            return;
        }

        $plan = self::preview($order, $courier, $settings);

        if ($plan['blocks'] !== [] || $plan['warnings'] !== []) {
            return;
        }

        self::book($order, $courier, null, false, 'automatic');
    }

    private static function recordFailure(Order $order, string $courier, string $message, ?User $by, string $source): void
    {
        $message = mb_substr($message, 0, 480);

        Order::query()->whereKey($order->id)->update([
            'courier_booking_started_at' => null,
            'courier_last_error' => $message,
            'courier_last_error_at' => now(),
        ]);

        CourierEvent::query()->create([
            'order_id' => $order->id,
            'courier' => $courier,
            'source' => 'booking',
            'event_type' => 'booking_failed',
            'user_id' => $by?->id,
            'summary' => $message,
            'payload' => ['source' => $source],
        ]);

        SystemEvents::record('courier_booking_failed', 'Order #'.$order->id.' with '.$courier.': '.$message);
    }
}
