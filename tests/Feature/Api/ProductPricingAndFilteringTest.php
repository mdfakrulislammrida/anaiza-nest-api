<?php

namespace Tests\Feature\Api;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductPricingAndFilteringTest extends TestCase
{
    use RefreshDatabase;

    private function category(): Category
    {
        return Category::firstOrCreate(['slug' => 'tea-sets'], ['name' => 'Tea Sets']);
    }

    private function product(array $overrides = []): Product
    {
        static $n = 0;
        $n++;

        return Product::create([
            'category_id' => $overrides['category_id'] ?? $this->category()->id,
            'name' => $overrides['name'] ?? "Product {$n}",
            'slug' => $overrides['slug'] ?? "product-{$n}",
            'price' => $overrides['price'] ?? 1000,
            'sale_price' => $overrides['sale_price'] ?? null,
            'stock_quantity' => $overrides['stock_quantity'] ?? 10,
            'sku' => $overrides['sku'] ?? "SKU-{$n}",
            'is_active' => true,
            'is_new' => $overrides['is_new'] ?? false,
            'is_featured' => $overrides['is_featured'] ?? false,
        ]);
    }

    public function test_effective_price_and_discount_percent_accessors(): void
    {
        $onSale = $this->product(['price' => 1000, 'sale_price' => 800]);
        $this->assertSame(800, $onSale->effective_price);
        $this->assertSame(20, $onSale->discount_percent);

        $notOnSale = $this->product(['price' => 1000, 'sale_price' => null]);
        $this->assertSame(1000, $notOnSale->effective_price);
        $this->assertNull($notOnSale->discount_percent);

        // A sale_price that isn't actually lower than price should not count as a discount.
        $badSale = $this->product(['price' => 1000, 'sale_price' => 1200]);
        $this->assertSame(1000, $badSale->effective_price);
        $this->assertNull($badSale->discount_percent);
    }

    public function test_products_api_exposes_pricing_fields(): void
    {
        $this->product(['slug' => 'deal', 'price' => 1000, 'sale_price' => 750]);

        $response = $this->getJson('/api/products/deal');

        $response->assertOk()
            ->assertJsonPath('data.price', 1000)
            ->assertJsonPath('data.sale_price', 750)
            ->assertJsonPath('data.effective_price', 750)
            ->assertJsonPath('data.discount_percent', 25);
    }

    public function test_on_sale_filter_returns_only_discounted_products(): void
    {
        $this->product(['slug' => 'a', 'price' => 1000, 'sale_price' => 800]);
        $this->product(['slug' => 'b', 'price' => 1000, 'sale_price' => null]);

        $response = $this->getJson('/api/products?on_sale=1');

        $response->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame('a', $response->json('data.0.slug'));
    }

    public function test_is_featured_filter(): void
    {
        $this->product(['slug' => 'featured', 'is_featured' => true]);
        $this->product(['slug' => 'not-featured', 'is_featured' => false]);

        $response = $this->getJson('/api/products?is_featured=1');

        $response->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame('featured', $response->json('data.0.slug'));
    }

    public function test_price_range_filter(): void
    {
        $this->product(['slug' => 'cheap', 'price' => 500]);
        $this->product(['slug' => 'mid', 'price' => 2000]);
        $this->product(['slug' => 'expensive', 'price' => 10000]);

        $response = $this->getJson('/api/products?min_price=1000&max_price=5000');

        $response->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame('mid', $response->json('data.0.slug'));
    }

    public function test_sort_by_price_ascending_uses_effective_price(): void
    {
        $this->product(['slug' => 'a', 'price' => 3000, 'sale_price' => 100]); // effective 100
        $this->product(['slug' => 'b', 'price' => 500]); // effective 500

        $response = $this->getJson('/api/products?sort=price_asc');

        $response->assertOk();
        $this->assertSame('a', $response->json('data.0.slug'));
        $this->assertSame('b', $response->json('data.1.slug'));
    }

    public function test_sort_newest_orders_by_created_at_desc(): void
    {
        $older = $this->product(['slug' => 'older']);
        $older->forceFill(['created_at' => now()->subDays(2)])->save();
        $newer = $this->product(['slug' => 'newer']);

        $response = $this->getJson('/api/products?sort=newest');

        $response->assertOk();
        $this->assertSame('newer', $response->json('data.0.slug'));
    }
}
