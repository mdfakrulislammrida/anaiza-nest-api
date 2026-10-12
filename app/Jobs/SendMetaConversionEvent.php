<?php

namespace App\Jobs;

use App\Models\MarketingSetting;
use App\Models\Order;
use App\Support\ConversionApiHasher;
use App\Support\Redact;
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
 */
class SendMetaConversionEvent
{
    use Dispatchable, InteractsWithQueue, SerializesModels;

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

        if (! $settings?->meta_pixel_id || ! $settings?->meta_capi_access_token) {
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

        $payload = [
            'data' => [[
                'event_name' => 'Purchase',
                'event_time' => $this->eventTime ?? $this->order->created_at->timestamp,
                'event_id' => "order-{$this->order->id}",
                'action_source' => 'website',
                'event_source_url' => config('app.frontend_url').'/order-confirmation',
                'user_data' => $userData,
                'custom_data' => [
                    'currency' => 'BDT',
                    'value' => (float) $this->order->total,
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

        // With a test event code set, the event shows under Test events in Events Manager (remove the code once testing is done).
        if (filled($settings->meta_test_event_code)) {
            $payload['test_event_code'] = trim($settings->meta_test_event_code);
        }

        try {
            $response = Http::asJson()
                ->post("https://graph.facebook.com/v18.0/{$settings->meta_pixel_id}/events", [
                    ...$payload,
                    'access_token' => $settings->meta_capi_access_token,
                ]);

            if ($response->failed()) {
                Log::error('Meta Conversions API request failed.', [
                    'order_id' => $this->order->id,
                    'status' => $response->status(),
                    'body' => Redact::secrets($response->body(), [$settings->meta_capi_access_token]),
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('Meta Conversions API request threw an exception.', [
                'order_id' => $this->order->id,
                'error' => Redact::secrets($e->getMessage(), [$settings->meta_capi_access_token]),
            ]);
        }
    }
}
