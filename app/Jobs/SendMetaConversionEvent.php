<?php

namespace App\Jobs;

use App\Models\MarketingSetting;
use App\Models\Order;
use App\Support\ConversionApiHasher;
use App\Support\ConversionAttempts;
use App\Support\ConversionEvents;
use App\Support\MatchData;
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
 * Runs right after the response that caused it (there is no queue worker on the host), with short HTTP timeouts, and never throws: every try is written
 * to conversion_attempts, a failure that could work next time is repeated by `conversions:retry` (see ConversionAttempts), and a failure that cannot goes
 * to Settings > System health. The order is never affected. The log lines (no secret, no customer data):
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
        // auto (the first try), retry (the scheduled task) or manual (Send again on the order page).
        public string $trigger = 'auto',
    ) {}

    public function handle(): void
    {
        $settings = MarketingSetting::query()->first();
        $eventId = "order-{$this->order->id}";
        $context = ['order_id' => $this->order->id, 'event_id' => $eventId];
        $eventTime = $this->eventTime ?? $this->order->created_at->timestamp;

        if ($settings !== null && ! $settings->meta_capi_enabled) {
            Log::info('Meta Purchase skipped, switched off.', $context);
            ConversionAttempts::record($this->order, 'meta', $this->trigger, 'skipped', null, 'switched off in Marketing Settings', false, $eventTime);
            ConversionEvents::settle($this->order);

            return;
        }

        if (! $settings?->meta_pixel_id || ! $settings?->meta_capi_access_token) {
            Log::info('Meta Purchase skipped, not set up.', $context);
            ConversionAttempts::record($this->order, 'meta', $this->trigger, 'skipped', null, 'no pixel id or access token in Marketing Settings', false, $eventTime);
            ConversionEvents::settle($this->order);

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
            // The browser's and the server container's hashed external id, as it arrived (already hashed: never hashed again).
            'external_id' => ($externalId = MatchData::externalId($this->order->external_id)) ? [$externalId] : null,
            // Meta's browser cookie, passed on from checkout.
            'fbp' => MatchData::fbp($this->order->fbp),
            // The ad click: the cookie value as the browser had it, else built in Meta's format from the fbclid, so the sale is matched to the click that brought the visitor.
            'fbc' => MatchData::fbc($this->order->fbc) ?? (filled($this->order->fbclid) ? 'fb.1.'.($this->order->created_at->timestamp * 1000).'.'.$this->order->fbclid : null),
            'client_ip_address' => $this->clientIp ?? $this->order->client_ip,
            'client_user_agent' => $this->clientUserAgent ?? $this->order->client_user_agent,
        ]);

        $value = ConversionEvents::purchaseValue($this->order);

        $payload = [
            'data' => [[
                'event_name' => 'Purchase',
                'event_time' => $eventTime,
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
                ConversionAttempts::record($this->order, 'meta', $this->trigger, 'sent', $response->status(), null, false, $eventTime);
            } else {
                // Meta's own words when it gave them; else the start of what it sent (a page, not JSON, for instance). The token is always masked.
                $raw = trim(mb_substr((string) $response->body(), 0, 200));
                $error = Redact::secrets((string) ($body['error']['message'] ?? ($raw !== '' ? $raw : 'Meta replied without accepting the event.')), [$token]);
                $this->failed($context, $eventTime, $response->status(), "HTTP {$response->status()}. {$error}", ConversionAttempts::retryable($response->status(), null, (bool) ($body['error']['is_transient'] ?? false)), $body['error']['fbtrace_id'] ?? $body['fbtrace_id'] ?? null);
            }
        } catch (\Throwable $e) {
            $error = Redact::secrets($e->getMessage(), [$token]);
            $this->failed($context, $eventTime, null, "no answer. {$error}", true, null);
        }

        ConversionEvents::settle($this->order);
    }

    /**
     * One failed try: written down, logged, and (when nothing will try again by itself) put in Settings > System health.
     *
     * @param  array<string, mixed>  $context
     */
    private function failed(array $context, int $eventTime, ?int $status, string $error, bool $retryable, ?string $fbtrace): void
    {
        $attempt = ConversionAttempts::record($this->order, 'meta', $this->trigger, 'failed', $status, $error, $retryable, $eventTime);
        $again = $attempt?->next_retry_at !== null;

        Log::error('Meta Purchase failed.', $context + ['status' => $status, 'error' => $error, 'fbtrace_id' => $fbtrace, 'attempt' => $attempt?->attempt, 'will_retry' => $again]);

        if (! $again) {
            SystemEvents::record('capi_failed', "Meta Purchase for order #{$this->order->id}: {$error}");
        }
    }
}
