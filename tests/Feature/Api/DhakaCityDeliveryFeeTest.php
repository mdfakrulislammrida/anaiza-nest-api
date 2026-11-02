<?php

namespace Tests\Feature\Api;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\SiteSetting;
use App\Support\DeliveryFeeCalculator;
use App\Support\DhakaCity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Tests\Support\AdminRecords;
use Tests\TestCase;

/**
 * "Inside Dhaka" means Dhaka city only: ৳80 inside, ৳150 outside, free over ৳10,000 only inside.
 */
class DhakaCityDeliveryFeeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SiteSetting::create([
            'site_name' => 'Anaiza Nest',
            'delivery_fee_dhaka' => 80,
            'delivery_fee_outside_dhaka' => 150,
            'free_delivery_threshold' => 10000,
        ]);
    }

    private function product(int $price): Product
    {
        $category = Category::firstOrCreate(['slug' => 'tea-sets'], ['name' => 'Tea Sets']);

        return Product::create([
            'category_id' => $category->id,
            'name' => 'Test Product',
            'slug' => 'test-product-'.uniqid(),
            'price' => $price,
            'stock_quantity' => 10,
            'sku' => 'TP-'.uniqid(),
            'is_active' => true,
        ]);
    }

    private function order(Product $product, string $division, string $district, ?string $thana): TestResponse
    {
        return $this->postJson('/api/orders', array_filter([
            'customer_name' => 'Fakrul Islam',
            'customer_phone' => '01710000000',
            'customer_address' => 'House 12, Road 5',
            'division' => $division,
            'district' => $district,
            'thana' => $thana,
            'payment_method' => 'cod',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ], fn ($v) => $v !== null));
    }

    // --- the fee ---------------------------------------------------------

    public function test_a_dhaka_city_thana_pays_80(): void
    {
        foreach (['Banani', 'Uttara East', 'Tejgaon Industrial Area', 'Sher-e-Bangla Nagar', 'Wari', 'Mirpur'] as $thana) {
            $this->assertSame(80, DeliveryFeeCalculator::forAddress('Dhaka', 'Dhaka', $thana, 500), $thana);
        }
    }

    public function test_matching_ignores_case_and_extra_spaces_but_not_the_name(): void
    {
        $this->assertSame(80, DeliveryFeeCalculator::forAddress(' dhaka ', 'DHAKA', '  new   market ', 500));
        // A different spelling is a different name: it is not renamed or guessed.
        $this->assertSame(150, DeliveryFeeCalculator::forAddress('Dhaka', 'Dhaka', 'Newmarket', 500));
    }

    public function test_savar_keraniganj_and_the_other_upazilas_of_dhaka_district_pay_150(): void
    {
        foreach (['Savar', 'Keraniganj', 'Dhamrai', 'Dohar', 'Nawabganj'] as $thana) {
            $this->assertSame(150, DeliveryFeeCalculator::forAddress('Dhaka', 'Dhaka', $thana, 500), $thana);
            $this->assertSame(150, DeliveryFeeCalculator::forAddress('Dhaka', 'Dhaka', $thana, 20000), "$thana is never free");
        }
    }

    public function test_the_rest_of_the_dhaka_division_pays_150(): void
    {
        $places = [
            ['Gazipur', 'Gazipur Sadar'], ['Narayanganj', 'Narayanganj Sadar'], ['Munshiganj', 'Munshiganj Sadar'],
            ['Tangail', 'Tangail Sadar'], ['Faridpur', 'Faridpur Sadar'], ['Kishoreganj', 'Kishoreganj Sadar'],
        ];

        foreach ($places as [$district, $thana]) {
            $this->assertSame(150, DeliveryFeeCalculator::forAddress('Dhaka', $district, $thana, 500), $district);
        }
    }

    public function test_another_division_pays_150(): void
    {
        $this->assertSame(150, DeliveryFeeCalculator::forAddress('Chattogram', 'Chattogram', 'Kotwali', 500));
        // A Dhaka city thana name under another division is not Dhaka city.
        $this->assertSame(150, DeliveryFeeCalculator::forAddress('Sylhet', 'Dhaka', 'Banani', 500));
    }

    public function test_dhaka_city_is_free_over_10000_only(): void
    {
        $this->assertSame(0, DeliveryFeeCalculator::forAddress('Dhaka', 'Dhaka', 'Gulshan', 10001));
        $this->assertSame(80, DeliveryFeeCalculator::forAddress('Dhaka', 'Dhaka', 'Gulshan', 10000), 'the threshold itself is not free');
    }

    public function test_gazipur_over_10000_is_not_free(): void
    {
        $this->assertSame(150, DeliveryFeeCalculator::forAddress('Dhaka', 'Gazipur', 'Gazipur Sadar', 10001));
        $this->assertSame(150, DeliveryFeeCalculator::forAddress('Dhaka', 'Narayanganj', 'Narayanganj Sadar', 50000));
    }

    public function test_dhaka_district_without_a_thana_is_outside_and_logged(): void
    {
        Log::spy();

        $this->assertSame(150, DeliveryFeeCalculator::forAddress('Dhaka', 'Dhaka', '', 500));
        $this->assertSame(150, DeliveryFeeCalculator::forAddress('Dhaka', 'Dhaka', null, 20000));
        $this->assertSame(150, DeliveryFeeCalculator::forAddress('Dhaka', 'Dhaka', 'Somewhere New', 500));

        Log::shouldHaveReceived('warning')->times(3);
    }

    public function test_a_known_outside_address_is_not_logged(): void
    {
        Log::spy();

        DeliveryFeeCalculator::forAddress('Dhaka', 'Dhaka', 'Savar', 500);
        DeliveryFeeCalculator::forAddress('Dhaka', 'Gazipur', 'Gazipur Sadar', 500);

        Log::shouldNotHaveReceived('warning');
    }

    public function test_the_two_lists_are_complete_and_do_not_overlap(): void
    {
        $this->assertCount(50, DhakaCity::thanas());
        $this->assertCount(5, DhakaCity::outsideUpazilas());
        $this->assertSame([], array_values(array_intersect(DhakaCity::thanas(), DhakaCity::outsideUpazilas())));
        $this->assertSame(array_unique(DhakaCity::thanas()), DhakaCity::thanas());
    }

    // --- orders ----------------------------------------------------------

    public function test_an_order_in_dhaka_city_charges_80_and_is_free_over_10000(): void
    {
        $small = $this->order($this->product(500), 'Dhaka', 'Dhaka', 'Banani')->assertCreated();
        $this->assertSame(80, $small->json('data.delivery_fee'));
        $this->assertSame(580, $small->json('data.total'));

        $big = $this->order($this->product(10500), 'Dhaka', 'Dhaka', 'Banani')->assertCreated();
        $this->assertSame(0, $big->json('data.delivery_fee'));
        $this->assertSame(10500, $big->json('data.total'));
    }

    public function test_an_order_in_savar_charges_150_and_the_server_recomputes_the_total(): void
    {
        $response = $this->order($this->product(500), 'Dhaka', 'Dhaka', 'Savar')->assertCreated();

        $this->assertSame(150, $response->json('data.delivery_fee'));
        $this->assertSame(650, $response->json('data.total'));
        $this->assertSame('Savar', Order::latest('id')->first()->ship_thana, 'the stored name is the one that arrived');
    }

    public function test_an_order_in_gazipur_over_10000_is_not_free(): void
    {
        $response = $this->order($this->product(10500), 'Dhaka', 'Gazipur', 'Gazipur Sadar')->assertCreated();

        $this->assertSame(150, $response->json('data.delivery_fee'));
        $this->assertSame(10650, $response->json('data.total'));
    }

    public function test_an_order_with_an_unknown_dhaka_thana_still_goes_through_at_150(): void
    {
        Log::spy();

        $response = $this->order($this->product(500), 'Dhaka', 'Dhaka', 'Somewhere New')->assertCreated();

        $this->assertSame(150, $response->json('data.delivery_fee'));
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_an_order_already_stored_keeps_its_fee(): void
    {
        // Placed under the old rule: Gazipur with the Dhaka-division fee.
        $order = AdminRecords::order([
            'ship_division' => 'Dhaka', 'ship_district' => 'Gazipur', 'ship_thana' => 'Gazipur Sadar',
            'subtotal' => 12000, 'delivery_fee' => 0, 'total' => 12000,
        ]);

        $fresh = Order::find($order->id);

        $this->assertSame(0, $fresh->delivery_fee);
        $this->assertSame(12000, $fresh->total);

        // Changing a setting, or looking the order up, never touches it.
        SiteSetting::first()->update(['delivery_fee_outside_dhaka' => 200]);
        $this->assertSame(0, Order::find($order->id)->delivery_fee);
    }

    // --- the API ---------------------------------------------------------

    public function test_the_quote_endpoint_answers_for_dhaka_city_and_outside(): void
    {
        $city = $this->getJson('/api/delivery-quote?division=Dhaka&district=Dhaka&thana=Banani&subtotal=500')->assertOk();
        $this->assertSame('dhaka_city', $city->json('data.zone'));
        $this->assertTrue($city->json('data.inside_dhaka_city'));
        $this->assertSame(80, $city->json('data.fee'));
        $this->assertFalse($city->json('data.free_delivery'));
        $this->assertSame(10000, $city->json('data.free_delivery_over'));
        $this->assertSame(['min' => 1, 'max' => 3], $city->json('data.delivery_days'));

        $free = $this->getJson('/api/delivery-quote?division=Dhaka&district=Dhaka&thana=Banani&subtotal=10001');
        $this->assertSame(0, $free->json('data.fee'));
        $this->assertTrue($free->json('data.free_delivery'));
        $this->assertSame(80, $free->json('data.standard_fee'));

        $gazipur = $this->getJson('/api/delivery-quote?division=Dhaka&district=Gazipur&thana=Gazipur%20Sadar&subtotal=10001');
        $this->assertSame('outside_dhaka', $gazipur->json('data.zone'));
        $this->assertFalse($gazipur->json('data.inside_dhaka_city'));
        $this->assertSame(150, $gazipur->json('data.fee'));
        $this->assertFalse($gazipur->json('data.free_delivery'));
        $this->assertSame(['min' => 3, 'max' => 5], $gazipur->json('data.delivery_days'));
    }

    public function test_the_quote_endpoint_quotes_the_same_number_the_order_charges(): void
    {
        foreach ([['Dhaka', 'Dhaka', 'Dhanmondi'], ['Dhaka', 'Dhaka', 'Savar'], ['Dhaka', 'Narayanganj', 'Rupganj'], ['Khulna', 'Khulna', 'Sonadanga']] as [$d, $di, $t]) {
            $quote = $this->getJson('/api/delivery-quote?'.http_build_query(['division' => $d, 'district' => $di, 'thana' => $t, 'subtotal' => 700]))->json('data.fee');
            $charged = $this->order($this->product(700), $d, $di, $t)->json('data.delivery_fee');

            $this->assertSame($charged, $quote, "$di / $t");
        }
    }

    public function test_the_quote_endpoint_without_a_thana_says_outside_and_logs_nothing(): void
    {
        Log::spy();

        $response = $this->getJson('/api/delivery-quote?division=Dhaka&district=Dhaka&subtotal=500')->assertOk();

        $this->assertSame(150, $response->json('data.fee'));
        $this->assertSame('dhaka_district_without_thana', $response->json('data.reason'));
        Log::shouldNotHaveReceived('warning');
    }

    public function test_the_quote_endpoint_rejects_a_bad_subtotal(): void
    {
        $this->getJson('/api/delivery-quote?division=Dhaka&subtotal=abc')->assertStatus(422);
        $this->getJson('/api/delivery-quote?division=Dhaka&subtotal=-5')->assertStatus(422);
    }

    public function test_site_settings_policy_carries_the_dhaka_city_rule(): void
    {
        $policy = $this->getJson('/api/site-settings')->assertOk()->json('data.policy');

        $this->assertSame('Dhaka', $policy['dhaka_city']['division']);
        $this->assertSame('Dhaka', $policy['dhaka_city']['district']);
        $this->assertCount(50, $policy['dhaka_city']['thanas']);
        $this->assertContains('Banani', $policy['dhaka_city']['thanas']);
        $this->assertSame(['Dhamrai', 'Dohar', 'Keraniganj', 'Nawabganj', 'Savar'], $policy['dhaka_city']['outside_upazilas']);
        $this->assertSame(10000, $policy['free_delivery_threshold']);
        $this->assertSame(80, $policy['delivery_fee_dhaka']);
        $this->assertSame(150, $policy['delivery_fee_outside_dhaka']);
    }

    public function test_the_promo_default_says_dhaka_city_and_a_saved_copy_of_the_previous_default_follows_it(): void
    {
        SiteSetting::first()->update(['promo_text' => 'Free delivery inside Dhaka on orders over ৳10,000']);

        $this->assertSame(
            'Free delivery inside Dhaka city on orders over ৳10,000',
            $this->getJson('/api/site-settings')->json('data.promo_text'),
        );
    }
}
