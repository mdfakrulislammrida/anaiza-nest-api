<?php

namespace App\Support\Courier;

use App\Models\CourierEvent;
use App\Models\CourierSetting;
use App\Models\Order;
use App\Support\System\SystemEvents;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Steadfast's webhook receiver. Steadfast POSTs JSON and waits up to five seconds for a 2xx, so the answer is quick and the
 * work happens after it.
 *
 * Verified exactly as Steadfast documents, on the raw request body:
 *  - Authorization: Bearer <our auth token>, and
 *  - X-Signature: the hex HMAC-SHA256 of the raw body keyed with the same token.
 * Both are compared with hash_equals; either failing is a 401. With no token saved (or Steadfast switched off) the endpoint
 * does not exist (404): a missing token never means "accept everything".
 *
 * Their retry rule: a 5xx or no answer is tried again after 30 seconds and after 2 minutes; a 4xx never is. So 401 is only
 * for a bad signature, and 5xx only for a real fault on our side (the exception is left to bubble).
 *
 * Idempotency-Key is stored (unique), so a retried event is answered 200 and ignored. Every event is kept in courier_events;
 * only `delivery_status` can change an order, and it goes through CourierStatusApplier (never backwards, no cash marked from
 * a webhook alone). The others (tracking_update, consignment_update, return_list_accepted, payment_request, cancel_request,
 * return_request, pickup_request, user_update) are stored and not acted on: their sample payloads are empty in the docs.
 */
final class SteadfastWebhook
{
    public const EVENTS = [
        'delivery_status', 'tracking_update', 'consignment_update', 'return_list_accepted',
        'payment_request', 'cancel_request', 'return_request', 'pickup_request', 'user_update',
    ];

    public static function handle(Request $request): JsonResponse
    {
        $settings = CourierSetting::current();
        $token = (string) $settings->steadfast_webhook_token;

        if (! $settings->steadfast_enabled || $token === '') {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $raw = $request->getContent();
        $signature = strtolower(trim((string) $request->header('X-Signature')));
        $goodSignature = $signature !== '' && hash_equals(hash_hmac('sha256', $raw, $token), $signature);
        $goodBearer = hash_equals('Bearer '.$token, trim((string) $request->header('Authorization')));

        // Both are worked out before either is judged, so a wrong one cannot be told from a right one by how long this takes.
        if (! ($goodSignature && $goodBearer)) {
            Log::warning('A Steadfast webhook was refused: the signature or the token did not match.');

            return response()->json(['message' => 'Unauthorised.'], 401);
        }

        $data = json_decode($raw, true);

        if (! is_array($data)) {
            return response()->json(['message' => 'Not JSON.'], 400);
        }

        $type = is_string($data['notification_type'] ?? null) && $data['notification_type'] !== '' ? $data['notification_type'] : 'delivery_status';
        $key = (string) $request->header('Idempotency-Key');
        // Steadfast sends the same Idempotency-Key when it retries an event; without one the body itself identifies it.
        $key = 'steadfast:'.mb_substr($key !== '' ? $key : 'body-'.hash('sha256', $raw), 0, 150);

        $invoice = is_scalar($data['invoice'] ?? null) ? trim((string) $data['invoice']) : '';
        $consignment = is_scalar($data['consignment_id'] ?? null) ? trim((string) $data['consignment_id']) : '';
        $status = is_scalar($data['status'] ?? null) ? trim((string) $data['status']) : '';

        $order = self::order($invoice, $consignment);

        try {
            CourierEvent::query()->create([
                'order_id' => $order?->id,
                'courier' => CourierSetting::STEADFAST,
                'source' => 'webhook',
                'event_type' => in_array($type, self::EVENTS, true) ? $type : 'other',
                'status' => $status !== '' ? mb_substr($status, 0, 120) : null,
                'idempotency_key' => $key,
                'summary' => mb_substr('Steadfast sent '.$type.($status !== '' ? ': '.$status : '').($order === null && $invoice !== '' ? ' (no order of ours matches invoice '.mb_substr($invoice, 0, 40).')' : '').'.', 0, 480),
                'payload' => self::scrub($data),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Seen already: answer as if it just worked, so Steadfast stops retrying it.
            return response()->json(['ok' => true, 'duplicate' => true]);
        }

        $settings->forceFill(['steadfast_webhook_last_at' => now()])->saveQuietly();
        SystemEvents::mark('steadfast_webhook', 'A webhook arrived: '.$type.'.');

        if ($type !== 'delivery_status') {
            return response()->json(['ok' => true]);
        }

        if ($order === null) {
            Log::warning('A Steadfast delivery_status webhook named an order we do not have.', ['invoice' => mb_substr($invoice, 0, 40)]);

            return response()->json(['ok' => true]);
        }

        // The consignment must be the one we booked, when we know it.
        if ($consignment !== '' && filled($order->courier_consignment_id) && $consignment !== (string) $order->courier_consignment_id) {
            CourierEvent::query()->create([
                'order_id' => $order->id, 'courier' => CourierSetting::STEADFAST, 'source' => 'webhook', 'event_type' => 'webhook_mismatch',
                'summary' => 'A delivery_status for this order named a different consignment; ignored.',
            ]);

            return response()->json(['ok' => true]);
        }

        if ($status !== '') {
            dispatch(function () use ($order, $status): void {
                try {
                    CourierStatusApplier::apply($order, CourierSetting::STEADFAST, $status, 'webhook');
                } catch (\Throwable $e) {
                    Log::error('Applying a Steadfast webhook failed.', ['order_id' => $order->id, 'error' => get_class($e)]);
                    SystemEvents::record('courier_webhook_failed', 'Order #'.$order->id.': '.get_class($e));
                }
            })->afterResponse();
        }

        return response()->json(['ok' => true]);
    }

    private static function order(string $invoice, string $consignment): ?Order
    {
        $base = fn () => Order::query()->where('courier', CourierSetting::STEADFAST);

        $order = $invoice !== '' ? $base()->where('courier_invoice', $invoice)->first() : null;

        return $order ?? ($consignment !== '' ? $base()->where('courier_consignment_id', $consignment)->first() : null);
    }

    /**
     * What is kept of an event: the fields Steadfast documents, and for any other event only its plain values, with anything
     * that could be a person's name, number, address, email or a secret left out, and the whole capped.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function scrub(array $data): array
    {
        $drop = '/phone|mobile|contact|address|name|email|token|secret|password|key/i';
        $clean = function (array $values) use (&$clean, $drop): array {
            $out = [];

            foreach ($values as $name => $value) {
                if (is_string($name) && preg_match($drop, $name)) {
                    continue;
                }

                $out[$name] = is_array($value) ? $clean($value) : (is_scalar($value) || $value === null ? (is_string($value) ? mb_substr($value, 0, 200) : $value) : null);
            }

            return $out;
        };

        $out = $clean($data);

        return strlen((string) json_encode($out)) > 4000 ? ['truncated' => true, 'keys' => array_slice(array_keys($out), 0, 30)] : $out;
    }
}
