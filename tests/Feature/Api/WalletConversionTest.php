<?php

namespace Tests\Feature\Api;

use App\Filament\Resources\OrderResource\Pages\EditOrder;
use App\Filament\Support\PaymentActions;
use App\Jobs\SendMetaConversionEvent;
use App\Jobs\SendTikTokConversionEvent;
use App\Models\Category;
use App\Models\CookieConsentSetting;
use App\Models\MarketingSetting;
use App\Models\Order;
use App\Models\PaymentSetting;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class WalletConversionTest extends TestCase
{
    use RefreshDatabase;

    private const IP = '203.0.113.9';

    private const AGENT = 'Mozilla/5.0 (Linux; Android 14) TestBrowser/1.0';

    protected function setUp(): void
    {
        parent::setUp();

        PaymentSetting::create(['bkash_number' => '01711111111', 'nagad_number' => '01722222222']);
        $this->actingAs(User::factory()->create(['role_id' => null]));
    }

    private function product(): Product
    {
        return Product::first() ?? Product::create([
            'category_id' => Category::create(['name' => 'Tea Sets', 'slug' => 'tea-sets'])->id,
            'name' => 'Mug', 'slug' => 'mug', 'price' => 600, 'stock_quantity' => 100, 'sku' => 'M-1', 'is_active' => true,
        ]);
    }

    private function place(array $overrides = []): Order
    {
        static $n = 0;
        $n++;

        $response = $this->withServerVariables(['REMOTE_ADDR' => self::IP])
            ->withHeaders(['User-Agent' => self::AGENT])
            ->postJson('/api/orders', array_merge([
                'customer_name' => 'Fakrul Islam', 'customer_phone' => '0171000'.str_pad((string) $n, 4, '0', STR_PAD_LEFT),
                'customer_address' => 'House 1', 'division' => 'Dhaka', 'district' => 'Dhaka', 'thana' => 'Banani',
                'payment_method' => 'cod', 'marketing_consent' => true,
                'items' => [['product_id' => $this->product()->id, 'quantity' => 1]],
            ], $overrides))->assertCreated();

        return Order::findOrFail($response->json('data.id'));
    }

    private function wallet(array $overrides = []): Order
    {
        static $t = 0;
        $t++;

        return $this->place(array_merge([
            'payment_method' => 'bkash',
            'payment_sender_number' => '01733333333',
            'payment_trx_id' => 'TRX'.str_pad((string) $t, 7, '0', STR_PAD_LEFT).'AB',
        ], $overrides));
    }

    private function verify(Order $order): void
    {
        PaymentActions::decide($order->fresh(), 'verified', null);
    }

    // ---- cash on delivery: unchanged

    public function test_cash_on_delivery_is_sent_at_placement(): void
    {
        Bus::fake([SendMetaConversionEvent::class, SendTikTokConversionEvent::class]);

        $order = $this->place();

        Bus::assertDispatchedTimes(SendMetaConversionEvent::class, 1);
        Bus::assertDispatchedTimes(SendTikTokConversionEvent::class, 1);
        Bus::assertDispatched(SendMetaConversionEvent::class, fn (SendMetaConversionEvent $job) => $job->clientIp === self::IP
            && $job->clientUserAgent === self::AGENT && $job->eventTime === null);
        $this->assertNotNull($order->fresh()->conversion_sent_at);
        $this->assertNull($order->client_ip, 'Cash on delivery keeps no visitor details.');
    }

    // ---- wallet at placement: nothing sent, details kept

    public function test_a_wallet_order_sends_nothing_at_placement_and_keeps_the_visitor_details(): void
    {
        Bus::fake([SendMetaConversionEvent::class, SendTikTokConversionEvent::class]);

        $order = $this->wallet();

        Bus::assertNotDispatched(SendMetaConversionEvent::class);
        Bus::assertNotDispatched(SendTikTokConversionEvent::class);
        $order->refresh();
        $this->assertSame(self::IP, $order->client_ip);
        $this->assertSame(self::AGENT, $order->client_user_agent);
        $this->assertNull($order->conversion_sent_at);
    }

    public function test_a_wallet_order_from_a_visitor_who_refused_marketing_keeps_nothing(): void
    {
        CookieConsentSetting::create(['mode' => 'opt_in']);

        $order = $this->wallet(['marketing_consent' => false]);

        $this->assertNull($order->client_ip);
        $this->assertNull($order->client_user_agent);
    }

    // ---- wallet at verification

    public function test_verifying_sends_both_events_once_with_the_original_time_and_visitor_details(): void
    {
        Bus::fake([SendMetaConversionEvent::class, SendTikTokConversionEvent::class]);
        $order = $this->wallet();

        $this->verify($order);

        foreach ([SendMetaConversionEvent::class, SendTikTokConversionEvent::class] as $job) {
            Bus::assertDispatchedTimes($job, 1);
            Bus::assertDispatched($job, fn ($sent) => $sent->order->is($order)
                && $sent->clientIp === self::IP
                && $sent->clientUserAgent === self::AGENT
                && $sent->eventTime === $order->created_at->timestamp);
        }

        $order->refresh();
        $this->assertNotNull($order->conversion_sent_at);
        $this->assertNull($order->client_ip, 'The visitor details are cleared once they have been used.');
        $this->assertNull($order->client_user_agent);
    }

    public function test_an_order_older_than_seven_days_is_reported_as_of_now(): void
    {
        Bus::fake([SendMetaConversionEvent::class, SendTikTokConversionEvent::class]);
        $order = $this->wallet();
        Order::whereKey($order->id)->update(['created_at' => now()->subDays(9)]);
        Carbon::setTestNow(now());

        $this->verify($order);

        Bus::assertDispatched(SendMetaConversionEvent::class, fn ($job) => $job->eventTime === now()->timestamp);
        Bus::assertDispatched(SendTikTokConversionEvent::class, fn ($job) => $job->eventTime === now()->timestamp);
        Carbon::setTestNow();
    }

    public function test_an_order_just_inside_seven_days_keeps_its_own_time(): void
    {
        Bus::fake([SendMetaConversionEvent::class, SendTikTokConversionEvent::class]);
        $order = $this->wallet();
        $placed = now()->subDays(6)->subHours(23)->startOfSecond();
        Order::whereKey($order->id)->update(['created_at' => $placed]);

        $this->verify($order);

        Bus::assertDispatched(SendMetaConversionEvent::class, fn ($job) => $job->eventTime === $placed->timestamp);
    }

    public function test_marking_failed_sends_nothing(): void
    {
        Bus::fake([SendMetaConversionEvent::class, SendTikTokConversionEvent::class]);
        $order = $this->wallet();

        PaymentActions::decide($order->fresh(), 'failed', 'Amount was short');

        Bus::assertNotDispatched(SendMetaConversionEvent::class);
        Bus::assertNotDispatched(SendTikTokConversionEvent::class);
        $this->assertNull($order->fresh()->conversion_sent_at);
    }

    public function test_verifying_twice_sends_once(): void
    {
        Bus::fake([SendMetaConversionEvent::class, SendTikTokConversionEvent::class]);
        $order = $this->wallet();

        $this->verify($order);
        $this->verify($order);

        Bus::assertDispatchedTimes(SendMetaConversionEvent::class, 1);
        Bus::assertDispatchedTimes(SendTikTokConversionEvent::class, 1);
    }

    public function test_failing_and_then_verifying_sends_once_and_verifying_fail_verify_still_once(): void
    {
        Bus::fake([SendMetaConversionEvent::class, SendTikTokConversionEvent::class]);
        $order = $this->wallet();

        PaymentActions::decide($order->fresh(), 'failed', 'Could not find it');
        Bus::assertNotDispatched(SendMetaConversionEvent::class);

        $this->verify($order);
        PaymentActions::decide($order->fresh(), 'failed', 'Changed my mind');
        $this->verify($order);

        Bus::assertDispatchedTimes(SendMetaConversionEvent::class, 1);
        Bus::assertDispatchedTimes(SendTikTokConversionEvent::class, 1);
    }

    public function test_an_order_already_counted_at_placement_is_not_sent_again(): void
    {
        Bus::fake([SendMetaConversionEvent::class, SendTikTokConversionEvent::class]);
        $order = $this->wallet();
        // What the migration does to every order that existed before this change.
        Order::whereKey($order->id)->update(['conversion_sent_at' => $order->created_at]);

        $this->verify($order);

        Bus::assertNotDispatched(SendMetaConversionEvent::class);
        Bus::assertNotDispatched(SendTikTokConversionEvent::class);
    }

    public function test_in_opt_in_mode_a_verified_order_without_consent_is_never_sent(): void
    {
        Bus::fake([SendMetaConversionEvent::class, SendTikTokConversionEvent::class]);
        CookieConsentSetting::create(['mode' => 'opt_in']);
        $order = $this->wallet(['marketing_consent' => false]);

        $this->verify($order);

        Bus::assertNotDispatched(SendMetaConversionEvent::class);
        Bus::assertNotDispatched(SendTikTokConversionEvent::class);
    }

    public function test_in_opt_in_mode_a_verified_order_with_consent_is_sent(): void
    {
        Bus::fake([SendMetaConversionEvent::class, SendTikTokConversionEvent::class]);
        CookieConsentSetting::create(['mode' => 'opt_in']);
        $order = $this->wallet(['marketing_consent' => true]);

        $this->verify($order);

        Bus::assertDispatchedTimes(SendMetaConversionEvent::class, 1);
    }

    public function test_the_admin_action_itself_sends_the_sale(): void
    {
        Bus::fake([SendMetaConversionEvent::class, SendTikTokConversionEvent::class]);
        $order = $this->wallet();

        Livewire::test(EditOrder::class, ['record' => $order->getRouteKey()])
            ->callAction('markPaymentVerified', ['note' => null]);

        Bus::assertDispatchedTimes(SendMetaConversionEvent::class, 1);
        Bus::assertDispatchedTimes(SendTikTokConversionEvent::class, 1);
    }

    public function test_without_a_queue_worker_the_calls_go_out_after_the_response(): void
    {
        Http::fake();
        MarketingSetting::create([
            'meta_pixel_id' => '111', 'meta_capi_access_token' => 'token',
            'tiktok_pixel_id' => '222', 'tiktok_events_api_access_token' => 'token',
        ]);
        $order = $this->wallet();
        Http::assertNothingSent();

        $this->verify($order);
        $this->app->terminate();

        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'graph.facebook.com'));
        Http::assertSent(fn ($request) => str_contains($request->url(), 'tiktok.com'));
    }

    // ---- the jobs report the given time and the same event id

    public function test_the_meta_event_carries_the_event_id_the_given_time_and_the_visitor_details(): void
    {
        Http::fake();
        MarketingSetting::create(['meta_pixel_id' => '111', 'meta_capi_access_token' => 'token']);
        $order = $this->wallet();

        (new SendMetaConversionEvent($order->fresh(), self::IP, self::AGENT, 1_700_000_000))->handle();

        Http::assertSent(function ($request) use ($order) {
            $event = $request->data()['data'][0];

            return $event['event_name'] === 'Purchase'
                && $event['event_id'] === "order-{$order->id}"
                && $event['event_time'] === 1_700_000_000
                && $event['user_data']['client_ip_address'] === self::IP
                && $event['user_data']['client_user_agent'] === self::AGENT;
        });
    }

    public function test_the_tiktok_event_carries_the_event_id_the_given_time_and_the_visitor_details(): void
    {
        Http::fake();
        MarketingSetting::create(['tiktok_pixel_id' => '222', 'tiktok_events_api_access_token' => 'token']);
        $order = $this->wallet();

        (new SendTikTokConversionEvent($order->fresh(), self::IP, self::AGENT, 1_700_000_000))->handle();

        Http::assertSent(function ($request) use ($order) {
            $event = $request->data()['data'][0];

            return $event['event'] === 'CompletePayment'
                && $event['event_id'] === "order-{$order->id}"
                && $event['event_time'] === 1_700_000_000
                && $event['user']['ip'] === self::IP
                && $event['user']['user_agent'] === self::AGENT;
        });
    }

    public function test_without_a_given_time_the_jobs_still_use_the_order_time(): void
    {
        Http::fake();
        MarketingSetting::create(['meta_pixel_id' => '111', 'meta_capi_access_token' => 'token']);
        $order = $this->place();

        (new SendMetaConversionEvent($order->fresh()))->handle();

        Http::assertSent(fn ($request) => $request->data()['data'][0]['event_time'] === $order->created_at->timestamp);
    }
}
