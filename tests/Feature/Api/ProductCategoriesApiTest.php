<?php

namespace Tests\Feature\Api;

use App\Http\Controllers\FeedController;
use App\Models\Category;
use App\Models\LandingPage;
use App\Models\Product;
use App\Models\SiteSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProductCategoriesApiTest extends TestCase
{
    use RefreshDatabase;

    private Category $teaSets;

    private Category $gifts;

    private Category $ceramic;

    protected function setUp(): void
    {
        parent::setUp();

        SiteSetting::create(['site_name' => 'Anaiza Nest']);
        // Created in this order on purpose: the primary is not the first by name or id in the lists below.
        $this->ceramic = Category::create(['name' => 'Ceramic', 'slug' => 'ceramic']);
        $this->gifts = Category::create(['name' => 'Gifts', 'slug' => 'gifts']);
        $this->teaSets = Category::create(['name' => 'Tea sets', 'slug' => 'tea-sets']);
    }

    private function product(string $name, Category $primary, array $extras = []): Product
    {
        $product = Product::create([
            'category_id' => $primary->id, 'name' => $name, 'slug' => str($name)->slug()->toString(),
            'price' => 1000, 'stock_quantity' => 5, 'sku' => 'SKU-'.str($name)->slug(), 'is_active' => true,
        ]);
        $product->syncCategories(array_map(fn (Category $category): int => $category->id, $extras));

        return $product;
    }

    public function test_the_product_page_lists_every_category_primary_first_and_keeps_category(): void
    {
        $product = $this->product('Peacock set', $this->teaSets, [$this->gifts, $this->ceramic]);

        $data = $this->getJson('/api/products/'.$product->slug)->assertOk()->json('data');

        // `category` is exactly what it was: the primary, in the same shape.
        $this->assertSame($this->teaSets->id, $data['category']['id']);
        $this->assertSame('tea-sets', $data['category']['slug']);
        $this->assertSame('Tea sets', $data['category']['name']);
        $this->assertSame(['id', 'name', 'slug', 'path', 'show_in_menu', 'menu_order', 'thumbnail'], array_keys($data['category']));

        // `categories` is new: the primary first, then the others by name.
        $this->assertSame([
            ['id' => $this->teaSets->id, 'slug' => 'tea-sets', 'name' => 'Tea sets', 'is_primary' => true],
            ['id' => $this->ceramic->id, 'slug' => 'ceramic', 'name' => 'Ceramic', 'is_primary' => false],
            ['id' => $this->gifts->id, 'slug' => 'gifts', 'name' => 'Gifts', 'is_primary' => false],
        ], $data['categories']);
    }

    public function test_a_single_category_product_has_one_entry_marked_primary(): void
    {
        $product = $this->product('Plain mug', $this->ceramic);

        $data = $this->getJson('/api/products/'.$product->slug)->assertOk()->json('data');

        $this->assertSame([['id' => $this->ceramic->id, 'slug' => 'ceramic', 'name' => 'Ceramic', 'is_primary' => true]], $data['categories']);
        $this->assertSame($this->ceramic->id, $data['category']['id']);
    }

    public function test_the_list_and_a_landing_block_carry_the_same_shape(): void
    {
        $this->product('Peacock set', $this->teaSets, [$this->gifts]);

        $row = $this->getJson('/api/products?category=gifts')->assertOk()->json('data.0');
        $this->assertSame(['tea-sets', 'gifts'], array_column($row['categories'], 'slug'));
        $this->assertSame([true, false], array_column($row['categories'], 'is_primary'));
        $this->assertSame('tea-sets', $row['category']['slug']);

        LandingPage::create([
            'title' => 'Eid offer', 'slug' => 'eid-offer', 'status' => 'published',
            'blocks' => [['type' => 'products', 'data' => ['is_visible' => true, 'source' => 'category', 'category_id' => $this->gifts->id, 'limit' => 5]]],
        ]);

        $block = $this->getJson('/api/landing-pages/eid-offer')->json('data.blocks.0.products.0');
        $this->assertSame(['tea-sets', 'gifts'], array_column($block['categories'], 'slug'));
    }

    public function test_the_primary_is_first_even_when_extras_have_a_position(): void
    {
        $product = $this->product('Peacock set', $this->teaSets, [$this->gifts, $this->ceramic]);
        $product->categories()->updateExistingPivot($this->ceramic->id, ['sort_order' => 1]);
        $product->categories()->updateExistingPivot($this->gifts->id, ['sort_order' => 2]);
        $product->categories()->updateExistingPivot($this->teaSets->id, ['sort_order' => 9]);

        $slugs = array_column($this->getJson('/api/products/'.$product->slug)->json('data.categories'), 'slug');

        $this->assertSame(['tea-sets', 'ceramic', 'gifts'], $slugs);
    }

    public function test_the_list_costs_the_same_number_of_queries_with_or_without_extras(): void
    {
        foreach (range(1, 4) as $n) {
            $this->product("Single {$n}", $this->teaSets);
        }

        DB::enableQueryLog();
        $this->getJson('/api/products?per_page=20')->assertOk();
        $single = count(DB::getQueryLog());

        foreach (range(1, 4) as $n) {
            $this->product("Multi {$n}", $this->gifts, [$this->teaSets, $this->ceramic]);
        }

        DB::flushQueryLog();
        $this->getJson('/api/products?per_page=20')->assertOk();
        $many = count(DB::getQueryLog());

        $this->assertSame($single, $many, 'the categories are loaded in one query for the whole list');
    }

    public function test_the_feed_cache_is_dropped_when_a_products_categories_change(): void
    {
        $product = $this->product('Peacock set', $this->teaSets);

        foreach (['google', 'meta', 'tiktok'] as $platform) {
            Cache::put(FeedController::cacheKey($platform), 'old', 600);
        }
        $product->update(['category_id' => $this->gifts->id]);
        foreach (['google', 'meta', 'tiktok'] as $platform) {
            $this->assertNull(Cache::get(FeedController::cacheKey($platform)), "{$platform}: primary changed");
        }

        Cache::put(FeedController::cacheKey('google'), 'old', 600);
        $product->fresh()->syncCategories([$this->ceramic->id]);
        $this->assertNull(Cache::get(FeedController::cacheKey('google')), 'extras changed');
    }
}
