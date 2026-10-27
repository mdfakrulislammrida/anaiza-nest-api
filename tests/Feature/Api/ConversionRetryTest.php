<?php

namespace Tests\Feature\Api;

use App\Filament\Resources\OrderResource\Pages\EditOrder;
use App\Filament\Support\ConversionActions;
use App\Filament\Support\PaymentActions;
use App\Models\Category;
use App\Models\ConversionAttempt;
use App\Models\CookieConsentSetting;
use App\Models\Customer;
use App\Models\MarketingSetting;
use App\Models\Order;
use App\Models\PaymentSetting;
use App\Models\Product;
use App\Models\SystemEvent;
use App\Models\User;
use App\Support\ConversionAttempts;
use App\Support\ConversionEvents;
use App\Support\System\HealthChecks;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A Purchase that Meta or TikTok did not get is tried again when the failure may be temporary (no answer, 429, 5xx), by the scheduled
 * `conversions:retry`, at most 3 times, 10 minutes, 30 minutes and 2 hours apart, within 24 hours. A refusal (400, 401, 403) is not repeated. The event id never
 * changes, nothing is sent twice after a success, and "Send Purchase again" on the order page cannot be pressed twice in a row. Everything is faked.
 */
class ConversionRetryTest extends TestCase
{
    use RefreshDatabase;

    private const META_TOKEN = 'EAAB-secret-meta-token-ABCD1234';

    private const IP = '203.0.113.9';

    private const AGENT = 'Mozilla/5.0 TestBrowser/1.0';

    private const FBP = 'fb.1.1700000000000.1234567890';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-28 10:00:00');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create(['role_id' => null]));
        PaymentSetting::create(['bkash_number' => '01711111111']);
        MarketingSetting::query()->create([
            'meta_pixel_id' => '123456789012345', 'meta_capi_access_token' => self::META_TOKEN,
            'tiktok_pixel_id' => 'TTPIXEL123', 'tiktok_events_api_access_token' => 'tiktok-secret-token-WXYZ5678',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ---- helpers

    /** Replaces the fake answers. $meta and $tiktok are what the platform says next: a response, or a sequence for several tries. */
    private function fake($meta = null, $tiktok = null): void
    {
        Http::swap(new HttpFactory);
        Http::fake([
            'graph.facebook.com/*' => $meta ?? Http::response(['events_received' => 1, 'fbtrace_id' => 'T'], 200),
            'business-api.tiktok.com/*' => $tiktok ?? Http::response(['code' => 0, 'message' => 'OK', 'request_id' => 'r'], 200),
        ]);
    }

    private function product(): Product
    {
        return Product::first() ?? Product::create([
            'category_id' => Category::create(['name' => 'Tea Sets', 'slug' => 'tea-sets'])->id,
            'name' => 'Mug', 'slug' => 'mug', 'price' => 1850, 'stock_quantity' => 100, 'sku' => 'M-1', 'is_active' => true,
        ]);
    }

    /** An order with the visitor's details kept on it, as checkout keeps them. */
    private function order(array $overrides = []): Order
    {
        $customer = Customer::create(['name' => 'Nusrat Jahan', 'email' => uniqid('nusrat').'@example.test', 'phone' => '01712345678', 'password' => 'x']);
        $order = Order::create($overrides + [
            'customer_id' => $customer->id, 'status' => 'pending', 'subtotal' => 3700, 'delivery_fee' => 80, 'total' => 3780, 'payment_method' => 'cod',
            'marketing_consent' => true, 'client_ip' => self::IP, 'client_user_agent' => self::AGENT, 'fbp' => self::FBP, 'external_id' => hash('sha256', '8801712345678'),
            'ship_name' => 'Nusrat Jahan', 'ship_email' => $customer->email, 'ship_phone' => '01712345678', 'ship_district' => 'Dhaka', 'ship_division' => 'Dhaka',
        ]);
        $order->items()->create(['product_id' => $this->product()->id, 'product_name' => 'Mug', 'quantity' => 2, 'price' => 1850, 'sku' => 'M-1']);

        return $order->fresh();
    }

    /** Cash on delivery placed: the first try goes out right after the response. */
    private function place(?Order $order = null): Order
    {
        $order ??= $this->order();
        ConversionEvents::sendAtPlacement($order, self::IP, self::AGENT);
        $this->app->terminate();
        (new \ReflectionProperty($this->app, 'terminatingCallbacks'))->setValue($this->app, []);

        return $order->fresh();
    }

    /** @return list<array<string, mixed>> */
    private function sent(string $host = 'graph.facebook.com'): array
    {
        return Http::recorded(fn (Request $request) => str_contains($request->url(), $host))->map(fn (array $pair) => $pair[0]->data())->values()->all();
    }

    private function row(Order $order, string $platform = 'meta'): ?ConversionAttempt
    {
        return ConversionAttempts::latest($order, $platform);
    }

    private function runRetry(int $minutesLater = 0): string
    {
        Carbon::setTestNow(now()->addMinutes($minutesLater));
        Artisan::call('conversions:retry');

        return Artisan::output();
    }

    private function metaFails(int $status, array $body = []): void
    {
        $this->fake(Http::response($body ?: ['error' => ['message' => 'Oops']], $status));
    }

    // ---- a temporary failure is tried again, and the sale then goes out

    public function test_a_timeout_is_tried_again_ten_minutes_later_with_the_same_event_id_and_then_succeeds(): void
    {
        $this->fake(Http::sequence()->pushFailedConnection('cURL error 28: Operation timed out')->push(['events_received' => 1, 'fbtrace_id' => 'T'], 200));
        $order = $this->place();

        $first = $this->row($order);
        $this->assertSame(['failed', true, null, 1], [$first->status, $first->retryable, $first->http_status, $first->attempt]);
        $this->assertEquals(now()->addMinutes(10), $first->next_retry_at);
        $this->assertSame('retrying', ConversionAttempts::state($order, 'meta')['state']);
        $this->assertSame(0, SystemEvent::query()->where('type', 'capi_failed')->count(), 'not an error yet: it is being tried again');
        $this->assertSame(self::IP, $order->client_ip, 'kept for the next try');
        $this->assertCount(1, $this->sent());

        $this->assertStringContainsString('0 sent again', $this->runRetry(5));
        $this->assertCount(1, $this->sent(), 'not due yet');

        $this->assertStringContainsString('1 sent again', $this->runRetry(6));
        $this->assertCount(2, $this->sent());

        [$one, $two] = $this->sent();
        $this->assertSame("order-{$order->id}", $one['data'][0]['event_id']);
        $this->assertSame($one['data'][0]['event_id'], $two['data'][0]['event_id'], 'the event id never changes, so Meta merges a try that did get through');
        $this->assertSame($one['data'][0]['event_time'], $two['data'][0]['event_time']);
        $this->assertSame(self::FBP, $two['data'][0]['user_data']['fbp'], 'the retry has the same match data');
        $this->assertSame(self::AGENT, $two['data'][0]['user_data']['client_user_agent']);

        $last = $this->row($order);
        $this->assertSame(['sent', 2, 'retry'], [$last->status, $last->attempt, $last->trigger]);
        $this->assertNull($last->next_retry_at);
        $this->assertSame('sent', ConversionAttempts::state($order, 'meta')['state']);

        $order->refresh();
        $this->assertNull($order->client_ip, 'forgotten once the sale is sent');
        $this->assertNull($order->fbp);
        $this->assertNotNull($order->conversion_sent_at);
    }

    public function test_the_delays_are_10_30_and_120_minutes_and_three_tries_are_the_limit(): void
    {
        $this->metaFails(503);
        $order = $this->place();
        $this->assertEquals(now()->addMinutes(10), $this->row($order)->next_retry_at);

        $this->runRetry(11);
        $this->assertSame(2, $this->row($order)->attempt);
        $this->assertEquals(now()->addMinutes(30), $this->row($order)->next_retry_at);

        $this->runRetry(31);
        $this->assertSame(3, $this->row($order)->attempt);
        $this->assertEquals(now()->addMinutes(120), $this->row($order)->next_retry_at);
        $this->assertSame(0, SystemEvent::query()->where('type', 'capi_failed')->count());

        $this->runRetry(121);
        $last = $this->row($order);
        $this->assertSame(4, $last->attempt, 'the first try and three more');
        $this->assertNull($last->next_retry_at, 'no fifth');
        $this->assertSame('failed', ConversionAttempts::state($order, 'meta')['state']);
        $this->assertStringContainsString('gave up after 4 tries', ConversionAttempts::state($order, 'meta')['label']);
        $this->assertSame(1, SystemEvent::query()->where('type', 'capi_failed')->count(), 'only now does it go to System health');
        $this->assertCount(4, $this->sent());

        $this->runRetry(600);
        $this->assertCount(4, $this->sent(), 'nothing more is ever sent by itself');
    }

    public function test_429_and_5xx_and_a_transient_flag_are_tried_again(): void
    {
        foreach ([[429, ['error' => ['message' => 'Too many calls']]], [500, ['error' => ['message' => 'x']]], [503, []], [400, ['error' => ['message' => 'Temporary', 'is_transient' => true]]]] as [$status, $body]) {
            $this->metaFails($status, $body);
            $order = $this->place();

            $this->assertTrue($this->row($order)->retryable, "HTTP {$status}");
            $this->assertNotNull($this->row($order)->next_retry_at, "HTTP {$status}");
        }
    }

    public function test_a_refusal_is_not_tried_again_and_goes_to_system_health(): void
    {
        foreach ([400, 401, 403, 404] as $status) {
            $this->metaFails($status, ['error' => ['message' => 'Invalid OAuth access token '.self::META_TOKEN, 'code' => 190]]);
            $order = $this->place();

            $row = $this->row($order);
            $this->assertFalse($row->retryable, "HTTP {$status}");
            $this->assertNull($row->next_retry_at);
            $this->assertSame('failed', ConversionAttempts::state($order, 'meta')['state']);
            $this->assertStringNotContainsString(self::META_TOKEN, (string) $row->error, 'no token in the record');
        }

        $this->assertSame(4, SystemEvent::query()->where('type', 'capi_failed')->count());
        $before = count($this->sent());

        $this->runRetry(60);
        $this->assertCount($before, $this->sent(), 'a refusal is never sent again by itself');

        $health = HealthChecks::tracking();
        $this->assertSame(HealthChecks::FAIL, $health['status']);
        $this->assertStringContainsString('4 Purchase send(s) failed for good', $health['detail']);
    }

    public function test_tiktok_busy_codes_are_tried_again_and_its_refusals_are_not(): void
    {
        $this->fake(null, Http::response(['code' => 50000, 'message' => 'Internal error', 'request_id' => 'a'], 200));
        $busy = $this->place();
        $this->assertTrue($this->row($busy, 'tiktok')->retryable);

        $this->fake(null, Http::response(['code' => 40105, 'message' => 'Access token is invalid', 'request_id' => 'b'], 200));
        $refused = $this->place();
        $this->assertFalse($this->row($refused, 'tiktok')->retryable);
        $this->assertNull($this->row($refused, 'tiktok')->next_retry_at);
    }

    public function test_health_is_fine_while_a_send_is_only_being_tried_again(): void
    {
        $this->metaFails(503);
        $this->place();

        $health = HealthChecks::tracking();

        $this->assertSame(HealthChecks::OK, $health['status']);
        $this->assertStringContainsString('1 Purchase(s) are being tried again', $health['detail']);
    }

    // ---- limits

    public function test_nothing_is_tried_for_a_sale_first_sent_more_than_24_hours_ago(): void
    {
        $this->metaFails(503);
        $order = $this->place();
        $before = count($this->sent());

        $output = $this->runRetry(25 * 60);   // the scheduler was stopped for a day

        $this->assertStringContainsString('1 given up', $output);
        $this->assertCount($before, $this->sent());
        $this->assertSame('failed', ConversionAttempts::state($order, 'meta')['state']);
        $this->assertStringContainsString('older than 24 hours', SystemEvent::query()->where('type', 'capi_failed')->firstOrFail()->message);
        $this->assertNull($order->fresh()->client_ip, 'and the visitor details are forgotten');
    }

    public function test_a_retry_that_runs_out_of_time_leaves_the_rest_for_the_next_run(): void
    {
        $this->metaFails(503);
        $order = $this->place();
        Carbon::setTestNow(now()->addMinutes(11));
        $before = count($this->sent());

        $summary = ConversionAttempts::retryDue(microtime(true) - 1);

        $this->assertSame(['retried' => 0, 'gave_up' => 0, 'left' => 1], $summary, 'the Meta retry is left for the next run');
        $this->assertCount($before, $this->sent());
        $this->assertNotNull($this->row($order)->next_retry_at, 'and not lost');
    }

    // ---- never twice

    public function test_after_a_success_nothing_is_sent_again(): void
    {
        $this->fake(Http::sequence()->pushFailedConnection('timeout')->push(['events_received' => 1], 200));
        $order = $this->place();
        $this->runRetry(11);
        $count = count($this->sent());

        $this->assertStringContainsString('0 sent again', $this->runRetry(60));
        $this->runRetry(600);

        $this->assertCount($count, $this->sent());
        $this->assertFalse(ConversionAttempts::canResend($order->fresh()), 'and there is nothing to send again');
        $this->assertSame(['ok' => false, 'message' => 'Nothing to send again: the sale has been sent, or this order may not be reported.'], ConversionAttempts::resend($order->fresh()));
        $this->assertCount($count, $this->sent());
    }

    public function test_two_runs_at_once_cannot_both_take_the_same_retry(): void
    {
        $this->metaFails(503);
        $this->place();
        Carbon::setTestNow(now()->addMinutes(11));
        $before = count($this->sent());

        $first = ConversionAttempts::retryDue();
        $second = ConversionAttempts::retryDue();

        $this->assertSame(1, $first['retried']);
        $this->assertSame(0, $second['retried'], 'the due time was taken by the first');
        $this->assertCount($before + 1, $this->sent());
    }

    public function test_a_switch_turned_off_meanwhile_ends_the_retry_without_a_call(): void
    {
        $this->metaFails(503);
        $order = $this->place();
        MarketingSetting::query()->update(['meta_capi_enabled' => false]);
        $before = count($this->sent());

        $this->runRetry(11);

        $this->assertCount($before, $this->sent());
        $this->assertSame('skipped', ConversionAttempts::state($order, 'meta')['state']);
    }

    public function test_a_wallet_order_is_retried_with_the_time_and_details_from_when_it_was_verified(): void
    {
        $this->fake(Http::sequence()->pushFailedConnection('timeout')->push(['events_received' => 1], 200));
        $order = $this->order(['payment_method' => 'bkash', 'payment_status' => 'awaiting_verification', 'payment_trx_id' => 'TRX1234567AB', 'created_at' => now()->subHours(3)]);

        PaymentActions::decide($order->fresh(), 'verified', null);
        $this->app->terminate();
        (new \ReflectionProperty($this->app, 'terminatingCallbacks'))->setValue($this->app, []);

        $this->assertSame('retrying', ConversionAttempts::state($order, 'meta')['state']);
        $this->runRetry(11);

        [$one, $two] = $this->sent();
        $this->assertSame($one['data'][0]['event_time'], $two['data'][0]['event_time']);
        $this->assertSame($order->created_at->timestamp, $two['data'][0]['event_time']);
        $this->assertSame(self::FBP, $two['data'][0]['user_data']['fbp']);
        $this->assertSame('sent', ConversionAttempts::state($order, 'meta')['state']);
        $this->assertNull($order->fresh()->client_ip);
    }

    // ---- the order page

    public function test_the_order_page_says_sent_retrying_or_failed_in_plain_words(): void
    {
        $this->metaFails(503);
        $order = $this->place();
        $this->assertStringContainsString('Meta Purchase: retrying', ConversionActions::summary($order)->toHtml());

        $this->metaFails(400);
        $refused = $this->place();
        $this->assertStringContainsString('Meta Purchase: failed', ConversionActions::summary($refused)->toHtml());

        $this->fake();
        $good = $this->place();
        $this->assertStringContainsString('Meta Purchase: sent', ConversionActions::summary($good)->toHtml());

        $none = $this->order(['source' => Order::SOURCE_LEGACY, 'legacy_order_id' => 9]);
        $this->assertStringContainsString('never reported', ConversionActions::summary($none)->toHtml());
    }

    public function test_send_again_is_offered_only_for_a_sale_that_did_not_get_through(): void
    {
        $this->metaFails(400);
        $failed = $this->place();
        $this->fake();
        $sent = $this->place();

        Livewire::test(EditOrder::class, ['record' => $failed->id])->assertActionVisible('resendPurchase');
        Livewire::test(EditOrder::class, ['record' => $sent->id])->assertActionHidden('resendPurchase');
    }

    public function test_send_again_sends_once_and_cannot_be_pressed_twice_in_a_row(): void
    {
        $this->metaFails(400);
        $order = $this->place();
        $before = count($this->sent());
        Carbon::setTestNow(now()->addMinutes(2));

        $page = Livewire::test(EditOrder::class, ['record' => $order->id]);
        $page->callAction('resendPurchase')->assertNotified('Purchase tried again');
        $this->assertCount($before + 1, $this->sent(), 'one call to Meta');
        $this->assertSame('manual', $this->row($order)->trigger);

        // It failed again (still a 400). A second press straight away does nothing.
        $page->callAction('resendPurchase')->assertNotified('Not sent');
        $this->assertCount($before + 1, $this->sent());

        // And not while another press is still running.
        Carbon::setTestNow(now()->addMinutes(5));
        Cache::add("conversion-resend:{$order->id}", 1, 60);
        $page->callAction('resendPurchase')->assertNotified('Not sent');
        $this->assertCount($before + 1, $this->sent());
        Cache::forget("conversion-resend:{$order->id}");
    }

    public function test_send_again_that_works_ends_the_failure_and_hides_the_button(): void
    {
        $this->metaFails(401);
        $order = $this->place();
        $this->fake();
        Carbon::setTestNow(now()->addMinutes(2));

        $page = Livewire::test(EditOrder::class, ['record' => $order->id]);
        $page->callAction('resendPurchase')->assertNotified('Purchase tried again');

        $this->assertSame('sent', ConversionAttempts::state($order, 'meta')['state']);
        $this->assertNull($order->fresh()->client_ip);
        $page->assertActionHidden('resendPurchase');
        $this->assertCount(1, $this->sent(), 'this one (the fake answers were replaced after the first try)');
    }

    public function test_send_again_will_not_go_out_without_the_browser_details_meta_requires(): void
    {
        $this->metaFails(400);
        $order = $this->place();
        Carbon::setTestNow(now()->addHours(30));
        ConversionEvents::eraseStale();
        $before = count($this->sent());

        $result = ConversionAttempts::resend($order->fresh());

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('erased', $result['message']);
        $this->assertCount($before, $this->sent());
    }

    public function test_a_visitor_who_may_not_be_reported_is_never_retried_or_resent(): void
    {
        $this->metaFails(503);
        $order = $this->place();
        $order->forceFill(['marketing_consent' => false])->saveQuietly();
        CookieConsentSetting::create(['mode' => 'opt_in']);
        $before = count($this->sent());

        $this->runRetry(11);

        $this->assertCount($before, $this->sent());
        $this->assertFalse(ConversionAttempts::canResend($order->fresh()));
    }
}
