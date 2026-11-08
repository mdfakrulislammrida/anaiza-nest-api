<?php

namespace Tests\Feature\Api;

use App\Jobs\SendMetaConversionEvent;
use App\Models\Category;
use App\Models\Customer;
use App\Models\MarketingSetting;
use App\Models\Order;
use App\Models\Product;
use App\Support\ConversionApiHasher;
use App\Support\ConversionEvents;
use App\Support\MatchData;
use App\Support\MetaTestEvent;
use App\Support\System\GoLiveCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Batch CAPI1: the Graph API version from config, num_items, placeholder strings never hashed, and no made-up source address. Fake data, fake HTTP.
 */
class MetaCapiCleanupTest extends TestCase
{
    use RefreshDatabase;

    private function settings(): void
    {
        MarketingSetting::query()->firstOrCreate([])->forceFill(['meta_pixel_id' => '123456789012345', 'meta_capi_access_token' => 'fake-token-for-tests'])->save();
    }

    private function order(array $items = [[2, 1850], [1, 600]], array $overrides = []): Order
    {
        $customer = Customer::create(['name' => 'Nusrat Jahan', 'email' => uniqid('n').'@example.test', 'phone' => '01712345678', 'password' => 'x']);
        $order = Order::create($overrides + [
            'customer_id' => $customer->id, 'status' => 'pending', 'subtotal' => 4300, 'delivery_fee' => 80, 'total' => 4380, 'payment_method' => 'cod',
            'ship_name' => 'Nusrat Jahan', 'ship_email' => $customer->email, 'ship_phone' => '01712345678', 'ship_district' => 'Dhaka', 'ship_division' => 'Dhaka',
        ]);
        $category = Category::firstOrCreate(['slug' => 'tea-sets'], ['name' => 'Tea Sets']);
        $product = Product::first() ?? Product::create(['category_id' => $category->id, 'name' => 'Mug', 'slug' => 'mug', 'price' => 1850, 'stock_quantity' => 50, 'sku' => 'M-1', 'is_active' => true]);

        foreach ($items as [$quantity, $price]) {
            $order->items()->create(['product_id' => $product->id, 'product_name' => 'Mug', 'quantity' => $quantity, 'price' => $price, 'sku' => 'M-1']);
        }

        return $order->fresh();
    }

    /** @return array<string, mixed> the one event sent to Meta */
    private function sendOne(Order $order): array
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['events_received' => 1, 'fbtrace_id' => 'trace'], 200)]);
        $this->settings();

        (new SendMetaConversionEvent($order, '203.0.113.9', 'TestBrowser/1.0'))->handle();

        $requests = Http::recorded(fn (Request $request) => str_contains($request->url(), 'graph.facebook.com'));
        $this->assertCount(1, $requests);

        return ['url' => $requests[0][0]->url(), 'body' => $requests[0][0]->data(), 'event' => $requests[0][0]->data()['data'][0]];
    }

    // --- 1. the Graph API version -------------------------------------------------

    public function test_the_graph_version_comes_from_config_with_a_current_default_and_is_used_by_the_purchase(): void
    {
        $this->assertSame('v25.0', config('services.meta.graph_version'));

        $sent = $this->sendOne($this->order());
        $this->assertStringStartsWith('https://graph.facebook.com/v25.0/123456789012345/events', $sent['url']);
        $this->assertStringNotContainsString('v18.0', $sent['url']);
    }

    public function test_a_changed_version_is_used_by_the_purchase_and_by_the_test_event(): void
    {
        config(['services.meta.graph_version' => 'v99.0']);

        $this->assertStringStartsWith('https://graph.facebook.com/v99.0/', $this->sendOne($this->order())['url']);

        Http::fake(['graph.facebook.com/*' => Http::response(['events_received' => 1], 200)]);
        $result = MetaTestEvent::send(MarketingSetting::query()->first(), '203.0.113.9', 'TestBrowser/1.0');
        $this->assertTrue($result['ok']);
        $this->assertSame(1, Http::recorded(fn (Request $request) => str_contains($request->url(), '/v99.0/'))->count(), 'the test event, after the fake was reset');
    }

    public function test_the_env_example_names_the_key(): void
    {
        $this->assertMatchesRegularExpression('/^META_GRAPH_VERSION=v\d+\.\d+$/m', file_get_contents(base_path('.env.example')));
    }

    // --- 2. num_items -------------------------------------------------------------

    public function test_num_items_is_the_sum_of_the_line_quantities_as_an_integer(): void
    {
        $custom = $this->sendOne($this->order([[2, 1850], [1, 600]]))['event']['custom_data'];

        $this->assertSame(3, $custom['num_items']);
        $this->assertIsInt($custom['num_items']);
        $this->assertSame('BDT', $custom['currency']);
    }

    public function test_num_items_is_left_out_when_there_are_no_items(): void
    {
        $custom = $this->sendOne($this->order([]))['event']['custom_data'];

        $this->assertArrayNotHasKey('num_items', $custom);
    }

    // --- 3. placeholder strings ----------------------------------------------------

    public function test_placeholder_words_are_never_hashed(): void
    {
        foreach (['null', 'NULL', 'undefined', ' none ', 'n/a', 'N/A', 'na', 'NaN', '-', '', '   ', null] as $value) {
            $label = var_export($value, true);
            $this->assertNull(ConversionApiHasher::email($value), "email {$label}");
            $this->assertNull(ConversionApiHasher::text($value), "text {$label}");
            $this->assertNull(ConversionApiHasher::country($value), "country {$label}");
            $this->assertSame(['first' => null, 'last' => null], ConversionApiHasher::fullName($value), "name {$label}");
            $this->assertNull(ConversionApiHasher::phone($value), "phone {$label}");
            $this->assertNull(MatchData::externalId($value), "external_id {$label}");
        }

        $this->assertNotSame(hash('sha256', ''), ConversionApiHasher::email('undefined') ?? 'omitted');
    }

    public function test_valid_values_hash_exactly_as_before(): void
    {
        $this->assertSame(hash('sha256', 'rafi@example.com'), ConversionApiHasher::email('  Rafi@Example.COM '));
        $this->assertSame(hash('sha256', 'dhaka'), ConversionApiHasher::text('Dhaka'));
        $this->assertSame(hash('sha256', 'cumilla'), ConversionApiHasher::text(' Cumilla '));
        $this->assertSame(['first' => hash('sha256', 'nusrat'), 'last' => hash('sha256', 'jahan')], ConversionApiHasher::fullName('Nusrat Jahan'));
        $this->assertSame(['first' => hash('sha256', 'rafi'), 'last' => null], ConversionApiHasher::fullName('Rafi'));
        $this->assertSame(hash('sha256', 'bd'), ConversionApiHasher::country());
        $this->assertSame(hash('sha256', '8801712345678'), ConversionApiHasher::phone('01712345678'));
        $this->assertSame(str_repeat('a', 64), MatchData::externalId(str_repeat('a', 64)));
    }

    public function test_a_placeholder_in_the_order_leaves_the_field_out_of_what_meta_receives(): void
    {
        $order = $this->order([[1, 1850]], ['ship_district' => 'N/A', 'ship_division' => 'undefined', 'ship_name' => 'null', 'ship_email' => 'none']);

        $user = $this->sendOne($order)['event']['user_data'];

        foreach (['em', 'fn', 'ln', 'ct', 'st'] as $key) {
            $this->assertArrayNotHasKey($key, $user, $key);
        }
        $this->assertArrayHasKey('ph', $user);
        $this->assertArrayHasKey('country', $user);
        $this->assertNotContains(hash('sha256', ''), array_merge(...array_map(fn ($v) => (array) $v, array_values($user))));
    }

    // --- 4. the source address -------------------------------------------------------

    public function test_the_source_url_has_the_trailing_slash_the_real_page_uses(): void
    {
        config(['app.env' => 'production', 'app.frontend_url' => 'https://anaizanest.com/']);

        $this->assertSame('https://anaizanest.com/order-confirmation/', ConversionEvents::orderConfirmationUrl());
        $this->assertSame('https://anaizanest.com/order-confirmation/', $this->sendOne($this->order())['event']['event_source_url']);
    }

    public function test_in_production_an_unset_or_developer_address_is_left_out_of_the_event(): void
    {
        foreach ([null, '', '   ', 'http://localhost:3000', 'http://127.0.0.1:3000', 'https://localhost'] as $value) {
            config(['app.env' => 'production', 'app.frontend_url' => $value]);

            $this->assertTrue(ConversionEvents::frontendUrlUnusable(), var_export($value, true));
            $this->assertNull(ConversionEvents::orderConfirmationUrl());

            $event = $this->sendOne($this->order())['event'];
            $this->assertArrayNotHasKey('event_source_url', $event, var_export($value, true));
            $this->assertSame('Purchase', $event['event_name'], 'the sale is still sent');
            Http::fake();
        }
    }

    public function test_outside_production_the_address_is_used_as_it_is(): void
    {
        config(['app.env' => 'local', 'app.frontend_url' => 'http://localhost:3000']);

        $this->assertFalse(ConversionEvents::frontendUrlUnusable());
        $this->assertSame('http://localhost:3000/order-confirmation/', $this->sendOne($this->order())['event']['event_source_url']);
    }

    public function test_go_live_warns_when_the_shop_address_is_not_set_for_production(): void
    {
        $find = fn () => collect(GoLiveCheck::run())->firstWhere('label', 'Meta source URL');

        config(['app.env' => 'production', 'app.frontend_url' => 'http://localhost:3000']);
        $row = $find();
        $this->assertNotNull($row);
        $this->assertSame(GoLiveCheck::WARN, $row['status']);
        $this->assertStringContainsString('FRONTEND_URL not set for production', $row['detail']);

        config(['app.frontend_url' => 'https://anaizanest.com']);
        $this->assertNull($find());

        config(['app.env' => 'local', 'app.frontend_url' => '']);
        $this->assertNull($find());
    }
}
