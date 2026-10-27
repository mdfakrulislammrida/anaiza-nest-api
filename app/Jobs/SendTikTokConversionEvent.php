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
 * Sends a server-side "CompletePayment" event to TikTok's Events API --
 * TikTok's server-side analog of Meta's Conversions API, for the same
 * ad-blocker/ITP-resilience reason. Shares its event_id with the
 * client-side pixel's CompletePayment event (see tracking.ts) so TikTok
 * deduplicates the two rather than double-counting the sale.
 *
 * Runs right after the response that caused it, with short HTTP timeouts, and never throws (see SendMetaConversionEvent for the log lines and the
 * retries; these say "TikTok Purchase ..."). TikTok has no equivalent of Meta's fbp and fbc, so those two are not sent; it does take the hashed external_id.
 */
class SendTikTokConversionEvent
{
    use Dispatchable, InteractsWithQueue, SerializesModels;

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

        if ($settings !== null && ! $settings->tiktok_events_enabled) {
            Log::info('TikTok Purchase skipped, switched off.', $context);
            ConversionAttempts::record($this->order, 'tiktok', $this->trigger, 'skipped', null, 'switched off in Marketing Settings', false, $eventTime);
            ConversionEvents::settle($this->order);

            return;
        }

        if (! $settings?->tiktok_pixel_id || ! $settings?->tiktok_events_api_access_token) {
            Log::info('TikTok Purchase skipped, not set up.', $context);
            ConversionAttempts::record($this->order, 'tiktok', $this->trigger, 'skipped', null, 'no pixel id or access token in Marketing Settings', false, $eventTime);
            ConversionEvents::settle($this->order);

            return;
        }

        $this->order->loadMissing(['customer', 'items']);
        $customer = (object) $this->order->recipient();

        // ip/user_agent are sent as plain values, not hashed -- unlike
        // email/phone, that's what TikTok's spec requires for them.
        $user = array_filter([
            'email' => ConversionApiHasher::email($customer->email) ? [ConversionApiHasher::email($customer->email)] : null,
            'phone' => ConversionApiHasher::phone($customer->phone) ? [ConversionApiHasher::phone($customer->phone)] : null,
            // The same hashed external id the browser and the server container send, as it arrived (never hashed again).
            'external_id' => MatchData::externalId($this->order->external_id),
            'ttclid' => filled($this->order->ttclid) ? $this->order->ttclid : null,
            'ip' => $this->clientIp ?? $this->order->client_ip,
            'user_agent' => $this->clientUserAgent ?? $this->order->client_user_agent,
        ]);

        $value = ConversionEvents::purchaseValue($this->order);

        $payload = [
            'event_source' => 'web',
            'event_source_id' => $settings->tiktok_pixel_id,
            'data' => [[
                'event' => 'CompletePayment',
                'event_time' => $eventTime,
                'event_id' => $eventId,
                'user' => $user,
                'properties' => [
                    'currency' => 'BDT',
                    // The items total after any discount, without the delivery fee (the same value the browser sends).
                    'value' => $value,
                    'order_id' => (string) $this->order->id,
                    'contents' => $this->order->items->map(fn ($item) => [
                        // The id the catalog feed uses (the SKU, else the product id), which is what a dynamic ad matches on.
                        'content_id' => $item->sku ?: (string) $item->product_id,
                        'content_type' => 'product',
                        'quantity' => $item->quantity,
                        'price' => (float) $item->price,
                    ])->all(),
                ],
                'page' => [
                    'url' => config('app.frontend_url').'/order-confirmation',
                ],
            ]],
        ];

        $testCode = trim((string) $settings->tiktok_test_event_code);

        // With a test event code set, the event shows under Test events in Events Manager (remove the code once testing is done).
        if ($testCode !== '') {
            $payload['test_event_code'] = $testCode;
        }

        $token = (string) $settings->tiktok_events_api_access_token;

        try {
            $response = Http::withHeaders(['Access-Token' => $token])->asJson()->connectTimeout(self::CONNECT_TIMEOUT)->timeout(self::TIMEOUT)
                ->post('https://business-api.tiktok.com/open_api/v1.3/event/track/', $payload);

            $body = $response->json() ?? [];

            // TikTok answers 200 with its own code in the body: 0 is success.
            if ($response->successful() && (int) ($body['code'] ?? -1) === 0) {
                Log::info('TikTok Purchase sent.', $context + ['value' => $value, 'request_id' => $body['request_id'] ?? null, 'test_code' => $testCode !== '']);
                ConversionAttempts::record($this->order, 'tiktok', $this->trigger, 'sent', $response->status(), null, false, $eventTime);
            } else {
                $raw = trim(mb_substr((string) $response->body(), 0, 200));
                $error = Redact::secrets((string) ($body['message'] ?? ($raw !== '' ? $raw : 'TikTok replied without accepting the event.')), [$token]);
                $code = isset($body['code']) ? (int) $body['code'] : null;
                $this->failed($context, $eventTime, $response->status(), "HTTP {$response->status()}".($code !== null ? ", code {$code}" : '').". {$error}", ConversionAttempts::retryable($response->status(), $code), $code, $body['request_id'] ?? null);
            }
        } catch (\Throwable $e) {
            $error = Redact::secrets($e->getMessage(), [$token]);
            $this->failed($context, $eventTime, null, "no answer. {$error}", true, null, null);
        }

        ConversionEvents::settle($this->order);
    }

    /**
     * One failed try: written down, logged, and (when nothing will try again by itself) put in Settings > System health.
     *
     * @param  array<string, mixed>  $context
     */
    private function failed(array $context, int $eventTime, ?int $status, string $error, bool $retryable, ?int $code, ?string $requestId): void
    {
        $attempt = ConversionAttempts::record($this->order, 'tiktok', $this->trigger, 'failed', $status, $error, $retryable, $eventTime);
        $again = $attempt?->next_retry_at !== null;

        Log::error('TikTok Purchase failed.', $context + ['status' => $status, 'code' => $code, 'error' => $error, 'request_id' => $requestId, 'attempt' => $attempt?->attempt, 'will_retry' => $again]);

        if (! $again) {
            SystemEvents::record('capi_failed', "TikTok Purchase for order #{$this->order->id}: {$error}");
        }
    }
}
