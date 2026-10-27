<?php

namespace Tests\Feature\Api;

use App\Filament\Pages\ManageMarketingSettings;
use App\Filament\Support\PaymentActions;
use App\Jobs\SendMetaConversionEvent;
use App\Jobs\SendTikTokConversionEvent;
use App\Models\Category;
use App\Models\ConversionAttempt;
use App\Models\Customer;
use App\Models\MarketingSetting;
use App\Models\Order;
use App\Models\PaymentSetting;
use App\Models\Product;
use App\Models\SystemEvent;
use App\Models\User;
use App\Support\ConversionEvents;
use App\Support\MetaTestEvent;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The shop's own server sends the Purchase to Meta and TikTok. These cover the two switches, the value (without delivery), the event id, what is
 * logged, and the wallet case (sent once, when the payment is verified, with the visitor details kept at checkout). Everything is faked: nothing leaves the machine.
 */
class LaravelPurchaseSendersTest extends TestCase
{
    use RefreshDatabase;

    private const META_TOKEN = 'EAAB-secret-meta-token-ABCD1234';

    private const TIKTOK_TOKEN = 'tiktok-secret-token-WXYZ5678';

    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create(['role_id' => null]));
        PaymentSetting::create(['bkash_number' => '01711111111']);
        Log::listen(function ($event): void {
            $this->logged[] = ['level' => $event->level, 'message' => $event->message, 'context' => $event->context];
        });
    }

    private function settings(array $overrides = []): MarketingSetting
    {
        $row = MarketingSetting::query()->firstOrCreate([]);
        $row->forceFill($overrides + [
            'meta_pixel_id' => '123456789012345', 'meta_capi_access_token' => self::META_TOKEN,
            'tiktok_pixel_id' => 'TTPIXEL123', 'tiktok_events_api_access_token' => self::TIKTOK_TOKEN,
        ])->save();

        return $row->fresh();
    }

    private function fakeBoth(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response(['events_received' => 1, 'messages' => [], 'fbtrace_id' => 'ABC123trace'], 200),
            'business-api.tiktok.com/*' => Http::response(['code' => 0, 'message' => 'OK', 'request_id' => 'req-1'], 200),
        ]);
    }

    private function product(): Product
    {
        return Product::first() ?? Product::create([
            'category_id' => Category::create(['name' => 'Tea Sets', 'slug' => 'tea-sets'])->id,
            'name' => 'Mug', 'slug' => 'mug', 'price' => 1850, 'stock_quantity' => 100, 'sku' => 'M-1', 'is_active' => true,
        ]);
    }

    /** An order with items 3,700 and delivery 80 (total 3,780). */
    private function order(array $overrides = []): Order
    {
        $customer = Customer::create(['name' => 'Nusrat Jahan', 'email' => uniqid('nusrat').'@example.test', 'phone' => '01712345678', 'password' => 'x']);
        $order = Order::create($overrides + [
            'customer_id' => $customer->id, 'status' => 'pending', 'subtotal' => 3700, 'delivery_fee' => 80, 'total' => 3780, 'payment_method' => 'cod',
            'ship_name' => 'Nusrat Jahan', 'ship_email' => $customer->email, 'ship_phone' => '01712345678', 'ship_district' => 'Dhaka', 'ship_division' => 'Dhaka',
        ]);
        $order->items()->create(['product_id' => $this->product()->id, 'product_name' => 'Mug', 'quantity' => 2, 'price' => 1850, 'sku' => 'M-1']);

        return $order->fresh();
    }

    /** Runs what was queued "after the response", once. */
    private function afterResponse(): void
    {
        $this->app->terminate();
        (new \ReflectionProperty($this->app, 'terminatingCallbacks'))->setValue($this->app, []);
    }

    private function sent(string $host): array
    {
        return Http::recorded(fn (Request $request) => str_contains($request->url(), $host))->map(fn (array $pair) => $pair[0]->data())->all();
    }

    private function lines(string $message): array
    {
        return array_values(array_filter($this->logged, fn (array $line) => $line['message'] === $message));
    }

    // ---- the switches

    public function test_a_new_row_sends_to_both_platforms(): void
    {
        $row = MarketingSetting::query()->create([]);

        $this->assertTrue($row->meta_capi_enabled);
        $this->assertTrue($row->tiktok_events_enabled);
        $this->assertTrue(MarketingSetting::query()->firstOrFail()->meta_capi_enabled, 'the database default is on, so today\'s behaviour does not change');
    }

    public function test_switched_on_it_sends_as_before(): void
    {
        $this->fakeBoth();
        $this->settings();
        $order = $this->order();

        (new SendMetaConversionEvent($order, '203.0.113.9', 'TestBrowser/1.0'))->handle();
        (new SendTikTokConversionEvent($order, '203.0.113.9', 'TestBrowser/1.0'))->handle();

        $this->assertCount(1, $this->sent('graph.facebook.com'));
        $this->assertCount(1, $this->sent('business-api.tiktok.com'));
    }

    public function test_switched_off_it_sends_nothing_and_says_so_once_without_a_token_or_customer_data(): void
    {
        $this->fakeBoth();
        $this->settings(['meta_capi_enabled' => false, 'tiktok_events_enabled' => false]);
        $order = $this->order();

        (new SendMetaConversionEvent($order))->handle();
        (new SendTikTokConversionEvent($order))->handle();

        Http::assertNothingSent();
        $this->assertCount(1, $this->lines('Meta Purchase skipped, switched off.'));
        $this->assertCount(1, $this->lines('TikTok Purchase skipped, switched off.'));
        $this->assertSame(['order_id' => $order->id, 'event_id' => 'order-'.$order->id], $this->lines('Meta Purchase skipped, switched off.')[0]['context']);
        $everything = json_encode($this->logged);
        foreach ([self::META_TOKEN, self::TIKTOK_TOKEN, 'nusrat', '01712345678', 'Nusrat'] as $secret) {
            $this->assertStringNotContainsString($secret, (string) $everything);
        }
    }

    public function test_each_switch_works_on_its_own(): void
    {
        $this->fakeBoth();
        $this->settings(['meta_capi_enabled' => false]);
        $order = $this->order();

        (new SendMetaConversionEvent($order))->handle();
        (new SendTikTokConversionEvent($order))->handle();

        $this->assertCount(0, $this->sent('graph.facebook.com'));
        $this->assertCount(1, $this->sent('business-api.tiktok.com'));
    }

    public function test_an_order_placed_with_the_switches_off_still_succeeds_and_sends_nothing(): void
    {
        $this->fakeBoth();
        $this->settings(['meta_capi_enabled' => false, 'tiktok_events_enabled' => false]);

        $this->postJson('/api/orders', [
            'customer_name' => 'Fakrul Islam', 'customer_phone' => '01710000001', 'customer_address' => 'House 1', 'division' => 'Dhaka', 'district' => 'Dhaka', 'thana' => 'Banani',
            'payment_method' => 'cod', 'marketing_consent' => true, 'items' => [['product_id' => $this->product()->id, 'quantity' => 1]],
        ])->assertCreated();
        $this->afterResponse();

        Http::assertNothingSent();
        $this->assertSame(1, Order::query()->count());
    }

    // ---- what is sent

    public function test_meta_gets_the_event_id_the_storefront_computes_and_the_value_without_delivery(): void
    {
        $this->fakeBoth();
        $this->settings();
        $order = $this->order();

        (new SendMetaConversionEvent($order, '203.0.113.9', 'TestBrowser/1.0'))->handle();

        $event = $this->sent('graph.facebook.com')[0]['data'][0];
        $this->assertSame('Purchase', $event['event_name']);
        $this->assertSame('order-'.$order->id, $event['event_id'], 'the storefront pushes order-<number> too');
        $this->assertSame('website', $event['action_source']);
        $this->assertSame(config('app.frontend_url').'/order-confirmation', $event['event_source_url']);
        $this->assertSame(['currency' => 'BDT', 'value' => 3700.0, 'order_id' => (string) $order->id, 'content_type' => 'product', 'content_ids' => ['M-1'], 'contents' => [['id' => 'M-1', 'quantity' => 2, 'item_price' => 1850.0]]], $event['custom_data']);
        $this->assertSame(['203.0.113.9'], [$event['user_data']['client_ip_address']]);
    }

    public function test_tiktok_gets_the_same_id_and_value(): void
    {
        $this->fakeBoth();
        $this->settings();
        $order = $this->order();

        (new SendTikTokConversionEvent($order))->handle();

        $body = $this->sent('business-api.tiktok.com')[0];
        $this->assertSame('CompletePayment', $body['data'][0]['event']);
        $this->assertSame('order-'.$order->id, $body['data'][0]['event_id']);
        $this->assertSame(3700.0, $body['data'][0]['properties']['value']);
        $this->assertSame('BDT', $body['data'][0]['properties']['currency']);
    }

    public function test_the_value_is_the_total_minus_delivery_after_a_discount_and_never_negative(): void
    {
        $discounted = $this->order(['subtotal' => 3700, 'discount_amount' => 200, 'total' => 3580]);
        $this->assertSame(3500.0, ConversionEvents::purchaseValue($discounted));

        $this->assertSame(0.0, ConversionEvents::purchaseValue($this->order(['total' => 50, 'delivery_fee' => 80])));
        $this->assertSame(1000.0, ConversionEvents::purchaseValue($this->order(['total' => 1000, 'delivery_fee' => 0])));
    }

    public function test_the_hashed_user_data_is_what_the_audit_says(): void
    {
        $this->fakeBoth();
        $this->settings();
        $order = $this->order(['fbclid' => 'IwAR-click']);

        (new SendMetaConversionEvent($order, '203.0.113.9', 'TestBrowser/1.0'))->handle();

        $user = $this->sent('graph.facebook.com')[0]['data'][0]['user_data'];
        $this->assertSame(['em', 'ph', 'fn', 'ln', 'ct', 'st', 'country', 'fbc', 'client_ip_address', 'client_user_agent'], array_keys($user));
        $this->assertArrayNotHasKey('fbp', $user, 'the storefront does not send the _fbp cookie to the order API');
        $this->assertMatchesRegularExpression('/^fb\.1\.\d{13}\.IwAR-click$/', $user['fbc']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $user['em'][0]);
        $this->assertStringNotContainsString($order->recipient()['email'], json_encode($user));
    }

    // ---- success and failure are logged, once, with no secret

    public function test_a_success_is_one_line_with_the_meta_answer(): void
    {
        $this->fakeBoth();
        $this->settings(['meta_test_event_code' => 'TEST12345']);
        $order = $this->order();

        (new SendMetaConversionEvent($order))->handle();

        $line = $this->lines('Meta Purchase sent.');
        $this->assertCount(1, $line);
        $this->assertSame(['order_id' => $order->id, 'event_id' => 'order-'.$order->id, 'value' => 3700.0, 'events_received' => 1, 'fbtrace_id' => 'ABC123trace', 'test_code' => true], $line[0]['context']);
        $this->assertSame('info', $line[0]['level']);
    }

    public function test_a_refusal_is_logged_as_an_error_and_noted_in_system_health_and_nothing_is_thrown(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Invalid OAuth access token '.self::META_TOKEN, 'code' => 190, 'fbtrace_id' => 'TRACE9']], 400)]);
        $this->settings();
        $order = $this->order();

        (new SendMetaConversionEvent($order))->handle();

        $line = $this->lines('Meta Purchase failed.');
        $this->assertCount(1, $line);
        $this->assertSame('error', $line[0]['level']);
        $this->assertSame(400, $line[0]['context']['status']);
        $this->assertSame('TRACE9', $line[0]['context']['fbtrace_id']);
        $this->assertStringNotContainsString(self::META_TOKEN, json_encode($this->logged));
        $note = SystemEvent::query()->where('type', 'capi_failed')->firstOrFail();
        $this->assertStringContainsString("order #{$order->id}", $note->message);
        $this->assertStringNotContainsString(self::META_TOKEN, $note->message);
    }

    public function test_no_answer_in_time_is_logged_and_never_reaches_the_caller(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));
        $this->settings();
        $order = $this->order();

        (new SendMetaConversionEvent($order))->handle();
        (new SendTikTokConversionEvent($order))->handle();

        $this->assertCount(1, $this->lines('Meta Purchase failed.'));
        $this->assertCount(1, $this->lines('TikTok Purchase failed.'));
        // No answer could be the platform being busy, so it is tried again (see ConversionRetryTest) and is not a System health error yet.
        $this->assertSame(0, SystemEvent::query()->where('type', 'capi_failed')->count());
        $this->assertSame(2, ConversionAttempt::query()->where('status', 'failed')->whereNotNull('next_retry_at')->count());
    }

    public function test_tiktok_refusing_inside_a_200_is_a_failure(): void
    {
        Http::fake(['business-api.tiktok.com/*' => Http::response(['code' => 40105, 'message' => 'Access token is invalid '.self::TIKTOK_TOKEN, 'request_id' => 'r'], 200)]);
        $this->settings();

        (new SendTikTokConversionEvent($this->order()))->handle();

        $this->assertCount(1, $this->lines('TikTok Purchase failed.'));
        $this->assertCount(0, $this->lines('TikTok Purchase sent.'));
        $this->assertStringNotContainsString(self::TIKTOK_TOKEN, json_encode($this->logged));
    }

    public function test_without_a_pixel_or_token_it_says_not_set_up_and_sends_nothing(): void
    {
        Http::fake();
        MarketingSetting::query()->create([]);

        (new SendMetaConversionEvent($this->order()))->handle();

        Http::assertNothingSent();
        $this->assertCount(1, $this->lines('Meta Purchase skipped, not set up.'));
    }

    public function test_the_calls_have_short_timeouts(): void
    {
        $this->assertSame([3, 8], [SendMetaConversionEvent::CONNECT_TIMEOUT, SendMetaConversionEvent::TIMEOUT]);
        $this->assertSame([3, 8], [SendTikTokConversionEvent::CONNECT_TIMEOUT, SendTikTokConversionEvent::TIMEOUT]);

        $source = file_get_contents(app_path('Jobs/SendMetaConversionEvent.php')).file_get_contents(app_path('Jobs/SendTikTokConversionEvent.php'));
        $this->assertSame(2, substr_count($source, '->connectTimeout(self::CONNECT_TIMEOUT)->timeout(self::TIMEOUT)'));
    }

    // ---- cash on delivery: the placement sends once, after the response

    public function test_a_cash_on_delivery_order_sends_once_after_the_response_to_both(): void
    {
        $this->fakeBoth();
        $this->settings();

        $response = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->postJson('/api/orders', [
            'customer_name' => 'Fakrul Islam', 'customer_phone' => '01710000002', 'customer_address' => 'House 1', 'division' => 'Dhaka', 'district' => 'Dhaka', 'thana' => 'Banani',
            'payment_method' => 'cod', 'marketing_consent' => true, 'items' => [['product_id' => $this->product()->id, 'quantity' => 2]],
        ])->assertCreated();

        // The jobs run when the response has been sent (the test kernel terminates the request, as the server does).
        $id = $response->json('data.id');
        $meta = $this->sent('graph.facebook.com');
        $this->assertCount(1, $meta, 'one send, however many times the placement code runs');
        $this->assertSame("order-{$id}", $meta[0]['data'][0]['event_id']);
        $this->assertSame((float) ($response->json('data.total') - $response->json('data.delivery_fee')), $meta[0]['data'][0]['custom_data']['value']);
        $this->assertCount(1, $this->sent('business-api.tiktok.com'));
    }

    // ---- wallet orders: sent once, when the payment is verified

    private function wallet(): Order
    {
        static $n = 0;
        $n++;

        $response = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.50'])->withHeaders(['User-Agent' => 'WalletBrowser/2.0'])->postJson('/api/orders', [
            'customer_name' => 'Wallet Buyer', 'customer_phone' => '0171000'.str_pad((string) (900 + $n), 4, '0', STR_PAD_LEFT), 'customer_address' => 'House 1',
            'division' => 'Dhaka', 'district' => 'Dhaka', 'thana' => 'Banani', 'payment_method' => 'bkash', 'payment_sender_number' => '01733333333',
            'payment_trx_id' => 'TRX'.str_pad((string) $n, 7, '0', STR_PAD_LEFT).'AB', 'marketing_consent' => true, 'fbclid' => 'IwAR-wallet',
            'items' => [['product_id' => $this->product()->id, 'quantity' => 2]],
        ])->assertCreated();

        return Order::findOrFail($response->json('data.id'));
    }

    public function test_a_wallet_order_sends_nothing_at_placement_and_one_purchase_when_it_is_verified(): void
    {
        $this->fakeBoth();
        $this->settings();
        $order = $this->wallet();
        $this->afterResponse();

        Http::assertNothingSent();

        PaymentActions::decide($order->fresh(), 'verified', null);
        $this->afterResponse();

        $meta = $this->sent('graph.facebook.com');
        $this->assertCount(1, $meta);
        $this->assertCount(1, $this->sent('business-api.tiktok.com'));
        $event = $meta[0]['data'][0];
        $this->assertSame("order-{$order->id}", $event['event_id']);
        $this->assertSame((float) ($order->total - $order->delivery_fee), $event['custom_data']['value']);
        // The details kept at checkout are used for that later call.
        $this->assertSame('203.0.113.50', $event['user_data']['client_ip_address']);
        $this->assertSame('WalletBrowser/2.0', $event['user_data']['client_user_agent']);
        $this->assertMatchesRegularExpression('/^fb\.1\.\d{13}\.IwAR-wallet$/', $event['user_data']['fbc']);
        $this->assertNull($order->fresh()->client_ip, 'and they are forgotten once used');
    }

    public function test_verifying_again_or_failing_and_verifying_never_sends_a_second_purchase(): void
    {
        $this->fakeBoth();
        $this->settings();
        $order = $this->wallet();
        $this->afterResponse();

        PaymentActions::decide($order->fresh(), 'verified', null);
        $this->afterResponse();
        PaymentActions::decide($order->fresh(), 'verified', null);
        $this->afterResponse();
        PaymentActions::decide($order->fresh(), 'failed', 'bounced');
        PaymentActions::decide($order->fresh(), 'verified', null);
        $this->afterResponse();

        $this->assertCount(1, $this->sent('graph.facebook.com'));
        $this->assertCount(1, $this->sent('business-api.tiktok.com'));
    }

    public function test_a_wallet_payment_marked_failed_sends_nothing(): void
    {
        $this->fakeBoth();
        $this->settings();
        $order = $this->wallet();

        PaymentActions::decide($order->fresh(), 'failed', 'no money arrived');
        $this->afterResponse();

        Http::assertNothingSent();
    }

    public function test_a_wallet_purchase_with_the_meta_switch_off_sends_to_tiktok_only(): void
    {
        $this->fakeBoth();
        $this->settings(['meta_capi_enabled' => false]);
        $order = $this->wallet();

        PaymentActions::decide($order->fresh(), 'verified', null);
        $this->afterResponse();

        $this->assertCount(0, $this->sent('graph.facebook.com'));
        $this->assertCount(1, $this->sent('business-api.tiktok.com'));
    }

    // ---- the admin form

    public function test_the_form_shows_both_switches_on_by_default_with_the_meta_advice(): void
    {
        Livewire::test(ManageMarketingSettings::class)
            ->assertFormSet(['meta_capi_enabled' => true, 'tiktok_events_enabled' => true])
            ->assertSee('Send Purchase to Meta from Laravel (CAPI)')
            ->assertSee('Send Purchase to TikTok from Laravel (Events API)')
            ->assertSee('Keep this ON. bKash, Nagad and Rocket orders only get a Purchase from Laravel (when payment is verified)')
            ->assertSee('Turn it OFF only if you accept losing Purchase for wallet orders.');
    }

    public function test_the_switches_and_the_server_address_are_saved_and_the_tokens_are_kept(): void
    {
        $this->settings();

        Livewire::test(ManageMarketingSettings::class)
            ->fillForm(['meta_capi_enabled' => false, 'tiktok_events_enabled' => false, 'server_container_url' => ' https://metrics.anaizanest.com '])
            ->call('save')
            ->assertHasNoFormErrors();

        $row = MarketingSetting::query()->firstOrFail();
        $this->assertFalse($row->meta_capi_enabled);
        $this->assertFalse($row->tiktok_events_enabled);
        $this->assertSame('https://metrics.anaizanest.com', $row->server_container_url);
        $this->assertSame(self::META_TOKEN, $row->meta_capi_access_token, 'a blank token field keeps the saved token');
    }

    public function test_the_server_address_must_be_https_without_a_slash_at_the_end(): void
    {
        foreach (['http://metrics.anaizanest.com', 'https://metrics.anaizanest.com/', 'metrics.anaizanest.com', 'https://metrics.anaizanest.com/path', 'https://bad host.com', 'https://localhost'] as $bad) {
            Livewire::test(ManageMarketingSettings::class)->fillForm(['server_container_url' => $bad])->call('save')->assertHasFormErrors(['server_container_url']);
        }

        foreach (['https://metrics.anaizanest.com', 'https://ywzcczvd.usv.stape.io', 'https://sgtm.example.com:8443', ''] as $good) {
            Livewire::test(ManageMarketingSettings::class)->fillForm(['server_container_url' => $good])->call('save')->assertHasNoFormErrors();
        }
    }

    public function test_the_public_settings_api_exposes_the_server_address_and_no_secret(): void
    {
        $this->settings(['server_container_url' => 'https://metrics.anaizanest.com', 'meta_capi_enabled' => false]);

        $json = $this->getJson('/api/marketing-settings')->assertOk()->assertJsonPath('data.server_container_url', 'https://metrics.anaizanest.com');

        $text = $json->getContent();
        $this->assertStringNotContainsString(self::META_TOKEN, $text);
        $this->assertStringNotContainsString(self::TIKTOK_TOKEN, $text);
        $this->assertStringNotContainsString('access_token', $text);
        $this->assertStringNotContainsString('capi_enabled', $text, 'the switches are for the admin only');
    }

    // ---- the Meta test button

    public function test_the_test_button_message_has_meta_s_answer_and_never_the_token(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::sequence()
            ->push(['events_received' => 1, 'messages' => [], 'fbtrace_id' => 'TRACE-OK'], 200)
            ->push(['error' => ['message' => 'Bad token '.self::META_TOKEN, 'code' => 190, 'fbtrace_id' => 'TRACE-BAD']], 400)]);

        $ok = MetaTestEvent::send($this->settings(['meta_test_event_code' => 'TEST12345']), '203.0.113.9', 'Admin');
        $this->assertTrue($ok['ok']);
        $this->assertStringContainsString('[events_received=1, fbtrace_id=TRACE-OK]', $ok['message']);

        $bad = MetaTestEvent::send(MarketingSetting::query()->firstOrFail(), null, null);

        $this->assertFalse($bad['ok']);
        $this->assertStringContainsString('fbtrace_id=TRACE-BAD', $bad['message']);
        $this->assertStringContainsString('events_received=0', $bad['message']);
        $this->assertStringContainsString('HTTP 400', $bad['message']);
        $this->assertStringContainsString('[code 190]', $bad['message']);
        $this->assertStringNotContainsString(self::META_TOKEN, $bad['message']);
    }
}
