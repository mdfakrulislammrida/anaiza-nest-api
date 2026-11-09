<?php

namespace Tests\Feature\Api;

use App\Filament\Resources\CategoryResource\Pages\ListCategories;
use App\Models\Category;
use App\Models\LandingPage;
use App\Models\Product;
use App\Models\SiteSetting;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CategoryListingsTest extends TestCase
{
    use RefreshDatabase;

    private Category $teaSets;

    private Category $gifts;

    private Category $ceramic;

    protected function setUp(): void
    {
        parent::setUp();

        SiteSetting::create(['site_name' => 'Anaiza Nest']);
        $this->teaSets = Category::create(['name' => 'Tea sets', 'slug' => 'tea-sets']);
        $this->gifts = Category::create(['name' => 'Gifts', 'slug' => 'gifts']);
        $this->ceramic = Category::create(['name' => 'Ceramic', 'slug' => 'ceramic']);
    }

    private function product(string $name, Category $primary, array $extras = [], array $overrides = []): Product
    {
        $product = Product::create($overrides + [
            'category_id' => $primary->id, 'name' => $name, 'slug' => str($name)->slug()->toString(),
            'price' => 1000, 'stock_quantity' => 5, 'sku' => 'SKU-'.str($name)->slug(), 'is_active' => true,
        ]);
        $product->syncCategories(array_map(fn (Category $category): int => $category->id, $extras));

        return $product;
    }

    private function names(string $url): array
    {
        return array_column($this->getJson($url)->assertOk()->json('data'), 'name');
    }

    public function test_a_product_in_three_categories_is_listed_once_in_each(): void
    {
        $this->product('Peacock set', $this->teaSets, [$this->gifts, $this->ceramic]);
        $this->product('Plain mug', $this->ceramic);

        $this->assertSame(['Peacock set'], $this->names('/api/products?category=tea-sets'));
        $this->assertSame(['Peacock set'], $this->names('/api/products?category=gifts'));
        $this->assertSame(['Peacock set', 'Plain mug'], $this->names('/api/products?category=ceramic'));
    }

    public function test_a_single_category_product_behaves_exactly_as_before(): void
    {
        $this->product('Only gift', $this->gifts);
        $this->product('Only tea', $this->teaSets);

        $this->assertSame(['Only gift'], $this->names('/api/products?category=gifts'));
        $this->assertSame([], $this->names('/api/products?category=no-such-category'));
        $this->assertSame(['Only gift', 'Only tea'], $this->names('/api/products'));
    }

    public function test_sorting_pagination_and_totals_do_not_change_with_extras(): void
    {
        // Five products in Tea sets (two of them also in Gifts, one in Gifts and Ceramic): every page is the same names in the same order, and nothing repeats.
        $this->product('E tea', $this->teaSets, [], ['price' => 500]);
        $this->product('D tea', $this->teaSets, [$this->gifts], ['price' => 900]);
        $this->product('C tea', $this->teaSets, [$this->gifts, $this->ceramic], ['price' => 100]);
        $this->product('B tea', $this->teaSets, [], ['price' => 300]);
        $this->product('A tea', $this->teaSets, [], ['price' => 700]);

        $page1 = $this->getJson('/api/products?category=tea-sets&per_page=2&page=1')->assertOk();
        $page2 = $this->getJson('/api/products?category=tea-sets&per_page=2&page=2')->assertOk();
        $page3 = $this->getJson('/api/products?category=tea-sets&per_page=2&page=3')->assertOk();

        $this->assertSame(5, $page1->json('meta.total'));
        $this->assertSame(3, $page1->json('meta.last_page'));
        $this->assertSame(['A tea', 'B tea'], array_column($page1->json('data'), 'name'));
        $this->assertSame(['C tea', 'D tea'], array_column($page2->json('data'), 'name'));
        $this->assertSame(['E tea'], array_column($page3->json('data'), 'name'));

        $this->assertSame(['C tea', 'B tea', 'E tea', 'A tea', 'D tea'], $this->names('/api/products?category=tea-sets&sort=price_asc'));
        $this->assertSame(['D tea', 'C tea'], $this->names('/api/products?category=gifts&sort=price_desc&per_page=10'));
        $this->assertSame(2, $this->getJson('/api/products?category=gifts')->json('meta.total'));
        $this->assertSame(1, $this->getJson('/api/products?category=ceramic')->json('meta.total'));
    }

    public function test_hidden_products_and_the_other_filters_still_apply_to_extras(): void
    {
        $this->product('Hidden extra', $this->teaSets, [$this->gifts], ['is_active' => false]);
        $this->product('Visible extra', $this->teaSets, [$this->gifts], ['price' => 400]);
        $this->product('Pricey extra', $this->teaSets, [$this->gifts], ['price' => 9000]);

        $this->assertSame(['Pricey extra', 'Visible extra'], $this->names('/api/products?category=gifts'));
        $this->assertSame(['Visible extra'], $this->names('/api/products?category=gifts&max_price=1000'));
        $this->assertSame(['Pricey extra'], $this->names('/api/products?category=gifts&search=Pricey'));
    }

    public function test_the_product_keeps_its_primary_category_in_the_category_field(): void
    {
        $this->product('Peacock set', $this->teaSets, [$this->gifts]);

        $this->assertSame('tea-sets', $this->getJson('/api/products?category=gifts')->json('data.0.category.slug'));
    }

    public function test_the_category_list_in_the_admin_counts_each_product_once_per_category(): void
    {
        $this->product('Peacock set', $this->teaSets, [$this->gifts, $this->ceramic]);
        $this->product('Gift box', $this->gifts);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create(['role_id' => null]));

        Livewire::test(ListCategories::class)
            ->assertTableColumnStateSet('products_count', 1, $this->teaSets->getKey())
            ->assertTableColumnStateSet('products_count', 2, $this->gifts->getKey())
            ->assertTableColumnStateSet('products_count', 1, $this->ceramic->getKey());
    }

    public function test_a_landing_page_category_block_lists_extras_once_and_keeps_its_order(): void
    {
        $this->product('Zebra mug', $this->ceramic);
        $this->product('Peacock set', $this->teaSets, [$this->ceramic]);
        $this->product('Sold out jar', $this->teaSets, [$this->ceramic], ['stock_quantity' => 0]);

        LandingPage::create([
            'title' => 'Eid offer', 'slug' => 'eid-offer', 'status' => 'published',
            'blocks' => [['type' => 'products', 'data' => ['is_visible' => true, 'source' => 'category', 'category_id' => $this->ceramic->id, 'limit' => 10]]],
        ]);

        $names = array_column($this->getJson('/api/landing-pages/eid-offer')->json('data.blocks.0.products'), 'name');

        // In stock first, then by name; each product once.
        $this->assertSame(['Peacock set', 'Zebra mug', 'Sold out jar'], $names);
    }
}
