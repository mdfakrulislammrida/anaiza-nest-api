<?php

namespace App\Jobs;

use App\Models\MarketingSetting;
use App\Models\Order;
use App\Support\ConversionApiHasher;
use App\Support\ConversionEvents;
use App\Support\Redact;
use App\Support\System\SystemEvents;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sends a server-side "Purchase" event to Meta's Conversions API so the
 * conversion is still tracked when the client-side pixel is blocked by an
 * ad-blocker or ITP. Shares its event_id with the client-side pixel's
 * Purchase event (see tracking.ts) so Meta deduplicates the two rather than
 * double-counting the sale.
 *
 * Runs right after the response that caused it (there is no queue worker on the host), with short HTTP timeouts, and never throws:
 * a failure is one log line and one note in Settings > System health, and the order is not affected. The log lines (no secret, no customer data):
 *   Meta Purchase sent.                    order_id, event_id, value, events_received, fbtrace_id, test_code
 *   Meta Purchase failed.                  order_id, event_id, status, error, fbtrace_id
 *   Meta Purchase skipped, switched off.   order_id, event_id
 *   Meta Purchase skipped, not set up.     order_id, event_id
 */
class SendMetaConversionEvent
{
    use Dispatchable, InteractsWithQueue, SerializesModels;

    /** The longest the call to Meta may take: it must never hold up the next request on a shared host. */
    public const CONNECT_TIMEOUT = 3;

    public const TIMEOUT = 8;

    /**
     * $clientIp/$clientUserAgent are captured from the checkout request
     * itself (see OrderController::store) -- by the time this job runs
     * there's no request to read them from any more.
     */
    public function __construct(
        public Order $order,
        public ?string $clientIp = null,
        public ?string $clientUserAgent = null,
        // A unix time to report the sale at. Null means when the order was placed; a wallet order is sent when its
        // payment is verified, which may be a while later.
        public ?int $eventTime = null,
    ) {}

    public function handle(): void
    {
        $settings = MarketingSetting::query()->first();
        $eventId = "order-{$this->order->id}";
        $context = ['order_id' => $this->order->id, 'event_id' => $eventId];

        if ($settings !== null && ! $settings->meta_capi_enabled) {
            Log::info('Meta Purchase skipped, switched off.', $context);

            return;
        }

        if (! $settings?->meta_pixel_id || ! $settings?->meta_capi_access_token) {
            Log::info('Meta Purchase skipped, not set up.', $context);

            return;
        }

        $this->order->loadMissing(['customer', 'items']);
        // Who the order is for, as typed at checkout (name, email, phone, district, division).
        $customer = (object) $this->order->recipient();

        // client_ip_address/client_user_agent are sent as plain values, not
        // hashed -- unlike em/ph, that's what Meta's spec requires for them.
        $userData = array_filter([
            'em' => ConversionApiHasher::email($customer->email) ? [ConversionApiHasher::email($customer->email)] : null,
            'ph' => ConversionApiHasher::phone($customer->phone) ? [ConversionApiHasher::phone($customer->phone)] : null,
            // Advanced matching: the same hashed fields the browser sends with its own Purchase event.
            'fn' => ($name = ConversionApiHasher::fullName($customer->name))['first'] ? [$name['first']] : null,
            'ln' => $name['last'] ? [$name['last']] : null,
            'ct' => ConversionApiHasher::text($customer->district) ? [ConversionApiHasher::text($customer->district)] : null,
            'st' => ConversionApiHasher::text($customer->division) ? [ConversionApiHasher::text($customer->division)] : null,
            'country' => [ConversionApiHasher::country()],
            // The ad click, in Meta's fbc format, so the sale is matched to the click that brought the visitor.
            'fbc' => filled($this->order->fbclid) ? 'fb.1.'.($this->order->created_at->timestamp * 1000).'.'.$this->order->fbclid : null,
            'client_ip_address' => $this->clientIp,
            'client_user_agent' => $this->clientUserAgent,
        ]);

        $value = ConversionEvents::purchaseValue($this->order);

        $payload = [
            'data' => [[
                'event_name' => 'Purchase',
                'event_time' => $this->eventTime ?? $this->order->created_at->timestamp,
                'event_id' => $eventId,
                'action_source' => 'website',
                'event_source_url' => config('app.frontend_url').'/order-confirmation',
                'user_data' => $userData,
                'custom_data' => [
                    'currency' => 'BDT',
                    // The items total after any discount, without the delivery fee: what the browser and the server container send too.
                    'value' => $value,
                    'order_id' => (string) $this->order->id,
                    'content_type' => 'product',
                    // The ids the catalog feed uses (the SKU, else the product id), which is what a dynamic ad matches on.
                    'content_ids' => $this->order->items->map(fn ($item) => $item->sku ?: (string) $item->product_id)->values()->all(),
                    'contents' => $this->order->items->map(fn ($item) => [
                        'id' => $item->sku ?: (string) $item->product_id,
                        'quantity' => $item->quantity,
                        'item_price' => (float) $item->price,
                    ])->all(),
                ],
            ]],
        ];

        $testCode = trim((string) $settings->meta_test_event_code);

        // With a test event code set, the event shows under Test events in Events Manager (remove the code once testing is done).
        if ($testCode !== '') {
            $payload['test_event_code'] = $testCode;
        }

        $token = (string) $settings->meta_capi_access_token;

        try {
            $response = Http::asJson()->connectTimeout(self::CONNECT_TIMEOUT)->timeout(self::TIMEOUT)
                ->post("https://graph.facebook.com/v18.0/{$settings->meta_pixel_id}/events", [...$payload, 'access_token' => $token]);

            $body = $response->json() ?? [];
            $received = (int) ($body['events_received'] ?? 0);

            if ($response->successful() && $received >= 1) {
                Log::info('Meta Purchase sent.', $context + ['value' => $value, 'events_received' => $received, 'fbtrace_id' => $body['fbtrace_id'] ?? null, 'test_code' => $testCode !== '']);

                return;
            }

            // Meta's own words when it gave them; else the start of what it sent (a page, not JSON, for instance). The token is always masked.
            $raw = trim(mb_substr((string) $response->body(), 0, 200));
            $error = Redact::secrets((string) ($body['error']['message'] ?? ($raw !== '' ? $raw : 'Meta replied without accepting the event.')), [$token]);
            Log::error('Meta Purchase failed.', $context + ['status' => $response->status(), 'error' => $error, 'fbtrace_id' => $body['error']['fbtrace_id'] ?? $body['fbtrace_id'] ?? null]);
            SystemEvents::record('capi_failed', "Meta Purchase for order #{$this->order->id}: HTTP {$response->status()}. {$error}");
        } catch (\Throwable $e) {
            $error = Redact::secrets($e->getMessage(), [$token]);
            Log::error('Meta Purchase failed.', $context + ['status' => null, 'error' => $error, 'fbtrace_id' => null]);
            SystemEvents::record('capi_failed', "Meta Purchase for order #{$this->order->id}: no answer. {$error}");
        }
    }
}
