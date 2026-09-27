<?php

namespace App\Jobs;

use App\Models\MarketingSetting;
use App\Models\Order;
use App\Support\ConversionApiHasher;
use Illuminate\Contracts\Queue\ShouldQueue;
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
 */
class SendTikTokConversionEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, SerializesModels;

    public function __construct(public Order $order) {}

    public function handle(): void
    {
        $settings = MarketingSetting::query()->first();

        if (! $settings?->tiktok_pixel_id || ! $settings?->tiktok_events_api_access_token) {
            return;
        }

        $this->order->loadMissing(['customer', 'items']);
        $customer = $this->order->customer;

        $user = array_filter([
            'email' => ConversionApiHasher::email($customer->email) ? [ConversionApiHasher::email($customer->email)] : null,
            'phone' => ConversionApiHasher::phone($customer->phone) ? [ConversionApiHasher::phone($customer->phone)] : null,
        ]);

        $payload = [
            'event_source' => 'web',
            'event_source_id' => $settings->tiktok_pixel_id,
            'data' => [[
                'event' => 'CompletePayment',
                'event_time' => $this->order->created_at->timestamp,
                'event_id' => "order-{$this->order->id}",
                'user' => $user,
                'properties' => [
                    'currency' => 'BDT',
                    'value' => (float) $this->order->total,
                    'contents' => $this->order->items->map(fn ($item) => [
                        'content_id' => (string) $item->product_id,
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

        try {
            $response = Http::withHeaders([
                'Access-Token' => $settings->tiktok_events_api_access_token,
            ])->asJson()->post('https://business-api.tiktok.com/open_api/v1.3/event/track/', $payload);

            if ($response->failed()) {
                Log::error('TikTok Events API request failed.', [
                    'order_id' => $this->order->id,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('TikTok Events API request threw an exception.', [
                'order_id' => $this->order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
