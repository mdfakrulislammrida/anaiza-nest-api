<?php

namespace Tests\Feature\Api;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckoutAndOrderLookupTest extends TestCase
{
    use RefreshDatabase;

    private function product(int $price = 1000, ?int $salePrice = null): Product
    {
        $category = Category::create(['name' => 'Tea Sets', 'slug' => 'tea-sets']);

        return Product::create([
            'category_id' => $category->id,
            'name' => 'Test Product',
            'slug' => 'test-product',
            'price' => $price,
            'sale_price' => $salePrice,
            'stock_quantity' => 10,
            'sku' => 'TP-001',
            'is_active' => true,
        ]);
    }

    private function basePayload(Product $product, array $overrides = []): array
    {
        return array_merge([
            'customer_name' => 'Fakrul Islam',
            'customer_phone' => '01710000000',
            'customer_address' => 'House 12, Road 5',
            'division' => 'Dhaka',
            'district' => 'Dhaka',
            'thana' => 'Banani',
            'payment_method' => 'cod',
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1],
            ],
        ], $overrides);
    }

    public function test_order_charges_sale_price_not_regular_price(): void
    {
        $product = $this->product(price: 1000, salePrice: 700);

        $response = $this->postJson('/api/orders', $this->basePayload($product));

        $response->assertCreated();
        $this->assertSame(700, $response->json('data.items.0.price'));
        $this->assertSame(700, $response->json('data.subtotal'));
    }

    public function test_delivery_is_free_inside_dhaka_over_threshold(): void
    {
        $product = $this->product(price: 2500);

        $response = $this->postJson('/api/orders', $this->basePayload($product, ['division' => 'Dhaka']));

        $response->assertCreated();
        $this->assertSame(0, $response->json('data.delivery_fee'));
    }

    public function test_delivery_is_flat_80_inside_dhaka_under_threshold(): void
    {
        $product = $this->product(price: 500);

        $response = $this->postJson('/api/orders', $this->basePayload($product, ['division' => 'Dhaka']));

        $response->assertCreated();
        $this->assertSame(80, $response->json('data.delivery_fee'));
    }

    public function test_delivery_is_flat_130_outside_dhaka_regardless_of_subtotal(): void
    {
        $product = $this->product(price: 5000);

        $response = $this->postJson('/api/orders', $this->basePayload($product, ['division' => 'Chattogram']));

        $response->assertCreated();
        $this->assertSame(130, $response->json('data.delivery_fee'));
    }

    public function test_email_is_optional_and_customer_is_deduped_by_phone(): void
    {
        $product = $this->product();

        $response = $this->postJson('/api/orders', $this->basePayload($product));
        $response->assertCreated();

        $this->assertSame(1, Customer::where('phone', '01710000000')->count());
        $this->assertNull(Customer::where('phone', '01710000000')->first()->email);

        // A second order from the same phone should reuse the same customer record.
        $this->postJson('/api/orders', $this->basePayload($product))->assertCreated();
        $this->assertSame(1, Customer::where('phone', '01710000000')->count());
    }

    public function test_order_lookup_succeeds_with_matching_id_and_phone(): void
    {
        $product = $this->product();
        $created = $this->postJson('/api/orders', $this->basePayload($product))->assertCreated();
        $orderId = $created->json('data.id');

        $response = $this->getJson("/api/orders/lookup?order_id={$orderId}&phone=01710000000");

        $response->assertOk()->assertJsonPath('data.id', $orderId);
    }

    public function test_order_lookup_fails_with_wrong_phone(): void
    {
        $product = $this->product();
        $created = $this->postJson('/api/orders', $this->basePayload($product))->assertCreated();
        $orderId = $created->json('data.id');

        $response = $this->getJson("/api/orders/lookup?order_id={$orderId}&phone=01799999999");

        $response->assertStatus(404);
    }
}
