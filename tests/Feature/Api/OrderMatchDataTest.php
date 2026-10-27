<?php

namespace Tests\Feature\Api;

use App\Filament\Support\PaymentActions;
use App\Jobs\SendMetaConversionEvent;
use App\Jobs\SendTikTokConversionEvent;
use App\Models\Category;
use App\Models\CookieConsentSetting;
use App\Models\Customer;
use App\Models\MarketingSetting;
use App\Models\Order;
use App\Models\PaymentSetting;
use App\Models\Product;
use App\Models\User;
use App\Support\ConversionEvents;
use App\Support\MatchData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * fbp, fbc and a hashed external_id travel from the storefront with the order, are checked for shape (a wrong one is dropped, the order is never refused),
 * are sent with the Purchase, and are forgotten once the sale has been sent. Everything is faked: nothing leaves the machine.
 */
class OrderMatchDataTest extends TestCase
{
    use RefreshDatabase;

    private const FBP = 'fb.1.1700000000000.1234567890';

    private const FBC = 'fb.1.1700000000000.IwAR0abc-DEF_123';

    private const IP = '203.0.113.9';

    private const AGENT = 'Mozilla/5.0 TestBrowser/1.0';

    private string $externalId;

    /** @var list<string> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->externalId = hash('sha256', '8801712345678');
        $this->actingAs(User::factory()->create(['role_id' => null]));
        PaymentSetting::create(['bkash_number' => '01711111111']);
        MarketingSetting::query()->create([
            'meta_pixel_id' => '123456789012345', 'meta_capi_access_token' => 'EAAB-secret-meta-token-ABCD1234',
            'tiktok_pixel_id' => 'TTPIXEL123', 'tiktok_events_api_access_token' => 'tiktok-secret-token-WXYZ5678',
        ]);
        $this->fake();
        Log::listen(function ($event): void {
            $this->logged[] = $event->message.' '.json_encode($event->context);
        });
    }

    /** A fresh set of fake answers (the first fake given wins, so a test that wants others starts over). */
    private function fake(array $meta = ['events_received' => 1, 'fbtrace_id' => 'T1'], int $metaStatus = 200): void
    {
        Http::swap(new HttpFactory);
        Http::fake([
            'graph.facebook.com/*' => Http::response($meta, $metaStatus),
            'business-api.tiktok.com/*' => Http::response(['code' => 0, 'message' => 'OK', 'request_id' => 'r1'], 200),
        ]);
    }

    private function product(): Product
    {
        return Product::first() ?? Product::create([
            'category_id' => Category::create(['name' => 'Tea Sets', 'slug' => 'tea-sets'])->id,
            'name' => 'Mug', 'slug' => 'mug', 'price' => 1850, 'stock_quantity' => 100, 'sku' => 'M-1', 'is_active' => true,
        ]);
    }

    private function place(array $overrides = []): Order
    {
        static $n = 0;
        $n++;

        $response = $this->withServerVariables(['REMOTE_ADDR' => self::IP])->withHeaders(['User-Agent' => self::AGENT])
            ->postJson('/api/orders', array_merge([
                'customer_name' => 'Nusrat Jahan', 'customer_email' => "nusrat{$n}@example.test", 'customer_phone' => '017123'.str_pad((string) $n, 5, '0', STR_PAD_LEFT),
                'customer_address' => 'House 1', 'division' => 'Dhaka', 'district' => 'Dhaka', 'thana' => 'Banani',
                'payment_method' => 'cod', 'marketing_consent' => true,
                'items' => [['product_id' => $this->product()->id, 'quantity' => 1]],
            ], $overrides))->assertCreated();

        return Order::findOrFail($response->json('data.id'));
    }

    private function withMatchData(array $extra = []): array
    {
        return $extra + ['fbp' => self::FBP, 'fbc' => self::FBC, 'external_id' => $this->externalId];
    }

    /** @return list<array<string, mixed>> */
    private function sent(string $host): array
    {
        return Http::recorded(fn (Request $request) => str_contains($request->url(), $host))->map(fn (array $pair) => $pair[0]->data())->all();
    }

    // ---- accepted, checked, stored

    private function wallet(array $extra = []): Order
    {
        static $t = 0;
        $t++;

        return $this->place($extra + ['payment_method' => 'bkash', 'payment_sender_number' => '01733333333', 'payment_trx_id' => 'TRX'.str_pad((string) $t, 7, '0', STR_PAD_LEFT).'AB']);
    }

    public function test_the_three_fields_are_stored_with_the_order(): void
    {
        // A wallet order is not sent at placement, so what was kept can be seen.
        $order = $this->wallet($this->withMatchData());

        $this->assertSame(self::FBP, $order->fbp);
        $this->assertSame(self::FBC, $order->fbc);
        $this->assertSame($this->externalId, $order->external_id);
    }

    public function test_a_value_of_the_wrong_shape_is_dropped_and_the_order_still_goes_through(): void
    {
        foreach ([
            ['fbp' => 'not a cookie', 'fbc' => 'fb.1.x.y', 'external_id' => 'nusrat@example.test'],
            ['fbp' => str_repeat('a', 500), 'fbc' => str_repeat('b', 900), 'external_id' => strtoupper($this->externalId)],
            ['fbp' => ['fb.1.1700000000000.1'], 'fbc' => ['x'], 'external_id' => [$this->externalId]],
            ['fbp' => 'fb.1.1700000000000.1<script>', 'fbc' => 'fb.1.1700000000000.a b', 'external_id' => substr($this->externalId, 1)],
            ['fbp' => '', 'fbc' => '  ', 'external_id' => ''],
        ] as $bad) {
            $order = $this->place($bad);

            $this->assertNull($order->fbp, json_encode($bad));
            $this->assertNull($order->fbc);
            $this->assertNull($order->external_id);
        }
    }

    public function test_only_a_valid_field_is_dropped_the_others_are_kept(): void
    {
        $order = $this->wallet(['fbp' => 'garbage', 'fbc' => self::FBC, 'external_id' => $this->externalId]);

        $this->assertNull($order->fbp);
        $this->assertSame(self::FBC, $order->fbc);
        $this->assertSame($this->externalId, $order->external_id);
    }

    public function test_the_cleaners_accept_exactly_the_documented_shapes(): void
    {
        $this->assertSame(self::FBP, MatchData::fbp(' '.self::FBP.' '));
        $this->assertNull(MatchData::fbp('fb.3.1700000000000.1'));
        $this->assertSame(self::FBC, MatchData::fbc(self::FBC));
        $this->assertNull(MatchData::fbc('fb.1.1700000000000.'));
        $this->assertSame($this->externalId, MatchData::externalId($this->externalId));
        $this->assertNull(MatchData::externalId(hash('md5', 'x')));
        $this->assertNull(MatchData::externalId(null));
        $this->assertNull(MatchData::externalId(12345));
    }

    public function test_nothing_is_kept_for_a_visitor_who_may_not_be_reported(): void
    {
        CookieConsentSetting::create(['mode' => 'opt_in']);

        $order = $this->place($this->withMatchData(['marketing_consent' => false]));

        $this->assertNull($order->fbp);
        $this->assertNull($order->fbc);
        $this->assertNull($order->external_id);
        $this->assertNull($order->client_ip);
    }

    public function test_orders_without_them_work_as_before(): void
    {
        $customer = Customer::create(['name' => 'Old', 'email' => 'old@example.test', 'phone' => '01712345678', 'password' => 'x']);
        $admin = Order::create(['customer_id' => $customer->id, 'status' => 'pending', 'subtotal' => 1000, 'delivery_fee' => 80, 'total' => 1080, 'payment_method' => 'cod', 'ship_name' => 'Old', 'ship_email' => 'old@example.test', 'ship_phone' => '01712345678']);
        $admin->items()->create(['product_id' => $this->product()->id, 'product_name' => 'Mug', 'quantity' => 1, 'price' => 1000, 'sku' => 'M-1']);

        (new SendMetaConversionEvent($admin->fresh(), '203.0.113.5', 'Browser/1'))->handle();

        $user = $this->sent('graph.facebook.com')[0]['data'][0]['user_data'];
        $this->assertArrayNotHasKey('fbp', $user);
        $this->assertArrayNotHasKey('external_id', $user);
        $this->assertArrayHasKey('em', $user);

        $legacy = Order::create(['customer_id' => $customer->id, 'status' => 'delivered', 'source' => Order::SOURCE_LEGACY, 'legacy_order_id' => 77, 'subtotal' => 500, 'delivery_fee' => 0, 'total' => 500, 'payment_method' => 'cod']);
        $this->assertNull($legacy->fbp);
        ConversionEvents::sendAtPlacement($legacy, '203.0.113.5', 'Browser/1');
        $this->app->terminate();
        $this->assertCount(1, $this->sent('graph.facebook.com'), 'an imported order is still never reported');
    }

    // ---- sent with the Purchase

    public function test_meta_gets_fbp_fbc_and_the_external_id_as_it_arrived(): void
    {
        $order = $this->wallet($this->withMatchData());

        (new SendMetaConversionEvent($order))->handle();

        $event = $this->sent('graph.facebook.com')[0]['data'][0];
        $user = $event['user_data'];

        $this->assertSame([$this->externalId], $user['external_id'], 'sent as it is, in a list, never hashed a second time');
        $this->assertNotSame([hash('sha256', $this->externalId)], $user['external_id']);
        $this->assertSame(self::FBP, $user['fbp']);
        $this->assertSame(self::FBC, $user['fbc']);
        $this->assertSame(self::IP, $user['client_ip_address']);
        $this->assertSame("order-{$order->id}", $event['event_id']);
        foreach (['em', 'ph', 'fn', 'ln', 'ct', 'st', 'country'] as $hashed) {
            $this->assertArrayHasKey($hashed, $user, "the hashed {$hashed} is still sent");
        }
    }

    public function test_the_stored_fbc_wins_over_the_one_built_from_fbclid(): void
    {
        $order = $this->wallet($this->withMatchData(['fbclid' => 'IwOTHER']));

        (new SendMetaConversionEvent($order))->handle();

        $this->assertSame(self::FBC, $this->sent('graph.facebook.com')[0]['data'][0]['user_data']['fbc']);
    }

    public function test_without_a_stored_fbc_it_is_still_built_from_fbclid(): void
    {
        $order = $this->wallet(['fbclid' => 'IwFromUrl']);

        (new SendMetaConversionEvent($order))->handle();

        $user = $this->sent('graph.facebook.com')[0]['data'][0]['user_data'];
        $this->assertSame('fb.1.'.($order->created_at->timestamp * 1000).'.IwFromUrl', $user['fbc']);
        $this->assertArrayNotHasKey('fbp', $user);
        $this->assertArrayNotHasKey('external_id', $user);
    }

    public function test_tiktok_gets_the_external_id_only(): void
    {
        $order = $this->wallet($this->withMatchData(['ttclid' => 'tt-click-1']));

        (new SendTikTokConversionEvent($order))->handle();

        $user = $this->sent('business-api.tiktok.com')[0]['data'][0]['user'];
        $this->assertSame($this->externalId, $user['external_id']);
        $this->assertSame('tt-click-1', $user['ttclid']);
        $this->assertArrayNotHasKey('fbp', $user);
        $this->assertArrayNotHasKey('fbc', $user);
    }

    public function test_a_wallet_order_uses_the_stored_values_when_the_payment_is_verified(): void
    {
        $order = $this->wallet($this->withMatchData());

        $this->assertSame(self::FBP, $order->fbp, 'kept while it waits to be verified');
        $this->assertSame(self::IP, $order->client_ip);
        $this->assertCount(0, $this->sent('graph.facebook.com'));

        PaymentActions::decide($order->fresh(), 'verified', null);
        $this->app->terminate();

        $user = $this->sent('graph.facebook.com')[0]['data'][0]['user_data'];
        $this->assertSame([$this->externalId], $user['external_id']);
        $this->assertSame(self::FBP, $user['fbp']);
        $this->assertSame(self::FBC, $user['fbc']);
        $this->assertSame(self::IP, $user['client_ip_address']);
        $this->assertSame(self::AGENT, $user['client_user_agent']);
    }

    // ---- what is forgotten, and when

    public function test_after_a_good_send_the_visitor_details_are_forgotten_but_the_click_id_stays(): void
    {
        // Cash on delivery: the sale goes out right after the response (in a test, inside the request), and the details are forgotten.
        $order = $this->place($this->withMatchData(['fbclid' => 'IwAR0abc']));

        $order->refresh();
        $this->assertNull($order->client_ip);
        $this->assertNull($order->client_user_agent);
        $this->assertNull($order->fbp);
        $this->assertNull($order->external_id);
        $this->assertSame(self::FBC, $order->fbc, 'a click id, like fbclid, is kept');
        $this->assertSame('IwAR0abc', $order->fbclid);
        $this->assertCount(1, $this->sent('graph.facebook.com'));
    }

    public function test_a_failed_send_keeps_the_details_for_the_tries_and_forgets_them_after_24_hours(): void
    {
        $this->fake(['error' => ['message' => 'Service unavailable']], 503);
        $order = $this->place($this->withMatchData());

        $order->refresh();
        $this->assertSame(self::IP, $order->client_ip, 'Meta has not got the sale yet, so it is tried again with the same details');
        $this->assertSame(self::FBP, $order->fbp);
        $this->assertSame($this->externalId, $order->external_id);

        Carbon::setTestNow(now()->addHours(25));
        $this->assertSame(1, ConversionEvents::eraseStale());

        $order->refresh();
        $this->assertNull($order->client_ip);
        $this->assertNull($order->fbp);
        $this->assertNull($order->external_id);
        $this->assertSame(self::FBC, $order->fbc);
        Carbon::setTestNow();
    }

    public function test_a_payment_marked_failed_forgets_what_was_kept_for_its_sale(): void
    {
        $order = $this->wallet($this->withMatchData());

        PaymentActions::decide($order->fresh(), 'failed', 'no such transaction');

        $order->refresh();
        $this->assertNull($order->client_ip);
        $this->assertNull($order->fbp);
        $this->assertNull($order->external_id);
        $this->assertCount(0, $this->sent('graph.facebook.com'));
    }

    // ---- nothing personal in the log

    public function test_no_raw_email_phone_or_name_reaches_the_log(): void
    {
        $this->fake(['error' => ['message' => 'Invalid parameter']], 400);
        $order = $this->place($this->withMatchData(['customer_email' => 'private.person@example.test', 'customer_phone' => '01799988877', 'customer_name' => 'Private Person']));
        (new SendTikTokConversionEvent($order))->handle();

        $text = strtolower(implode("\n", $this->logged));
        $this->assertStringContainsString('meta purchase failed.', $text);
        foreach (['private.person@example.test', '01799988877', '8801799988877', 'private person', 'privateperson'] as $needle) {
            $this->assertStringNotContainsString($needle, $text);
        }
        $this->assertStringNotContainsString('eaab-secret-meta-token', $text);
        $this->assertStringNotContainsString(self::FBP, $text);
        $this->assertStringNotContainsString($this->externalId, $text);
    }
}
