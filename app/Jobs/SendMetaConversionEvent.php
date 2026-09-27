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
 * Sends a server-side "Purchase" event to Meta's Conversions API so the
 * conversion is still tracked when the client-side pixel is blocked by an
 * ad-blocker or ITP. Shares its event_id with the client-side pixel's
 * Purchase event (see tracking.ts) so Meta deduplicates the two rather than
 * double-counting the sale.
 */
class SendMetaConversionEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, SerializesModels;

    public function __construct(public Order $order) {}

    public function handle(): void
    {
        $settings = MarketingSetting::query()->first();

        if (! $settings?->meta_pixel_id || ! $settings?->meta_capi_access_token) {
            return;
        }

        $this->order->loadMissing(['customer', 'items']);
        $customer = $this->order->customer;

        $userData = array_filter([
            'em' => ConversionApiHasher::email($customer->email) ? [ConversionApiHasher::email($customer->email)] : null,
            'ph' => ConversionApiHasher::phone($customer->phone) ? [ConversionApiHasher::phone($customer->phone)] : null,
        ]);

        $payload = [
            'data' => [[
                'event_name' => 'Purchase',
                'event_time' => $this->order->created_at->timestamp,
                'event_id' => "order-{$this->order->id}",
                'action_source' => 'website',
                'event_source_url' => config('app.frontend_url').'/order-confirmation',
                'user_data' => $userData,
                'custom_data' => [
                    'currency' => 'BDT',
                    'value' => (float) $this->order->total,
                    'content_type' => 'product',
                    'contents' => $this->order->items->map(fn ($item) => [
                        'id' => (string) $item->product_id,
                        'quantity' => $item->quantity,
                        'item_price' => (float) $item->price,
                    ])->all(),
                ],
            ]],
        ];

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
                    'body' => $response->body(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('Meta Conversions API request threw an exception.', [
                'order_id' => $this->order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
