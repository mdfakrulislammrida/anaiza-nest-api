<?php

namespace Tests\Feature\Api;

use App\Jobs\SendMetaConversionEvent;
use App\Jobs\SendTikTokConversionEvent;
use App\Models\Category;
use App\Models\MarketingSetting;
use App\Models\Order;
use App\Models\Product;
use App\Support\Redact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class TrackingTestCodesTest extends TestCase
{
    use RefreshDatabase;

    private const META_TOKEN = 'EAAB-secret-meta-token-123';

    private const TIKTOK_TOKEN = 'tiktok-secret-token-456';

    private function order(?string $sku = 'MUG-RED'): Order
    {
        Bus::fake([SendMetaConversionEvent::class, SendTikTokConversionEvent::class]);

        $product = Product::create([
            'category_id' => Category::create(['name' => 'Mugs', 'slug' => 'mugs'])->id,
            'name' => 'Red mug', 'slug' => 'red-mug', 'price' => 600, 'stock_quantity' => 20, 'sku' => $sku, 'is_active' => true,
        ]);

        $id = $this->postJson('/api/orders', [
            'customer_name' => 'Fakrul Islam', 'customer_phone' => '01710000001', 'customer_address' => 'House 1',
            'division' => 'Dhaka', 'district' => 'Dhaka', 'thana' => 'Banani', 'payment_method' => 'cod', 'marketing_consent' => true,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertCreated()->json('data.id');

        return Order::findOrFail($id);
    }

    private function settings(array $overrides = []): MarketingSetting
    {
        return MarketingSetting::create(array_merge([
            'meta_pixel_id' => '123456789012345', 'meta_capi_access_token' => self::META_TOKEN,
            'tiktok_pixel_id' => 'TTPIXEL123', 'tiktok_events_api_access_token' => self::TIKTOK_TOKEN,
        ], $overrides));
    }

    public function test_meta_event_carries_the_test_code_only_when_one_is_set(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['events_received' => 1])]);
        $order = $this->order();

        $settings = $this->settings();
        (new SendMetaConversionEvent($order))->handle();
        Http::assertSent(fn (Request $request) => ! array_key_exists('test_event_code', $request->data()));

        $settings->update(['meta_test_event_code' => ' TEST12345 ']);
        Http::fake(['graph.facebook.com/*' => Http::response(['events_received' => 1])]);
        (new SendMetaConversionEvent($order))->handle();
        Http::assertSent(fn (Request $request) => ($request->data()['test_event_code'] ?? null) === 'TEST12345');
    }

    public function test_tiktok_event_carries_the_test_code_only_when_one_is_set(): void
    {
        Http::fake(['business-api.tiktok.com/*' => Http::response(['code' => 0])]);
        $order = $this->order();

        $settings = $this->settings();
        (new SendTikTokConversionEvent($order))->handle();
        Http::assertSent(fn (Request $request) => ! array_key_exists('test_event_code', $request->data()));

        $settings->update(['tiktok_test_event_code' => 'TEST67890']);
        Http::fake(['business-api.tiktok.com/*' => Http::response(['code' => 0])]);
        (new SendTikTokConversionEvent($order))->handle();
        Http::assertSent(fn (Request $request) => ($request->data()['test_event_code'] ?? null) === 'TEST67890');
    }

    public function test_one_platforms_code_is_not_sent_to_the_other(): void
    {
        Http::fake(['*' => Http::response(['ok' => true])]);
        $order = $this->order();
        $this->settings(['meta_test_event_code' => 'TEST_META', 'tiktok_test_event_code' => null]);

        (new SendTikTokConversionEvent($order))->handle();

        Http::assertSent(fn (Request $request) => ! array_key_exists('test_event_code', $request->data()));
    }

    public function test_events_use_the_same_ids_as_the_catalog_feed(): void
    {
        Http::fake(['*' => Http::response(['ok' => true])]);
        $order = $this->order('MUG-RED');
        $this->settings();

        (new SendMetaConversionEvent($order))->handle();
        (new SendTikTokConversionEvent($order))->handle();

        Http::assertSent(function (Request $request) {
            if (! str_contains($request->url(), 'facebook')) {
                return false;
            }
            $custom = $request->data()['data'][0]['custom_data'];

            return $custom['content_ids'] === ['MUG-RED'] && $custom['contents'][0]['id'] === 'MUG-RED';
        });
        Http::assertSent(function (Request $request) {
            return str_contains($request->url(), 'tiktok') && json_encode($request->data()) !== false
                && str_contains(json_encode($request->data()), '"content_id":"MUG-RED"');
        });
    }

    public function test_the_access_token_is_never_written_to_the_log(): void
    {
        $logged = [];
        Log::listen(function ($event) use (&$logged) {
            $logged[] = $event->message.' '.json_encode($event->context);
        });
        // Each platform can only echo back the token it was sent.
        Http::fake([
            'graph.facebook.com/*' => Http::response('rejected '.self::META_TOKEN, 400),
            'business-api.tiktok.com/*' => Http::response('rejected '.self::TIKTOK_TOKEN, 400),
        ]);
        $order = $this->order();
        $this->settings();

        (new SendMetaConversionEvent($order))->handle();
        (new SendTikTokConversionEvent($order))->handle();

        $text = implode('
', $logged);
        $this->assertStringContainsString('Meta Conversions API request failed', $text);
        $this->assertStringContainsString('TikTok Events API request failed', $text);
        $this->assertStringContainsString('[redacted]', $text);
        $this->assertStringNotContainsString(self::META_TOKEN, $text);
        $this->assertStringNotContainsString(self::TIKTOK_TOKEN, $text);
    }

    public function test_redact_replaces_only_real_secrets(): void
    {
        $this->assertSame('token [redacted] failed', Redact::secrets('token abcdefgh12 failed', ['abcdefgh12']));
        $this->assertSame('a b c', Redact::secrets('a b c', [null, '', 'b']));
    }
}
