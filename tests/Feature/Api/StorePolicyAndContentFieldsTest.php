<?php

namespace Tests\Feature\Api;

use App\Models\Article;
use App\Models\Category;
use App\Models\Product;
use App\Models\SiteSetting;
use App\Support\DeliveryFeeCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StorePolicyAndContentFieldsTest extends TestCase
{
    use RefreshDatabase;

    private function product(int $price = 1000, array $overrides = []): Product
    {
        $category = Category::firstOrCreate(['slug' => 'tea-sets'], ['name' => 'Tea Sets']);

        return Product::create(array_merge([
            'category_id' => $category->id,
            'name' => 'Test Product',
            'slug' => 'test-product',
            'price' => $price,
            'stock_quantity' => 10,
            'sku' => 'TP-001',
            'is_active' => true,
        ], $overrides));
    }

    private function orderPayload(Product $product, string $division = 'Dhaka'): array
    {
        return [
            'customer_name' => 'Fakrul Islam',
            'customer_phone' => '01710000000',
            'customer_address' => 'House 12, Road 5',
            'division' => $division,
            'district' => $division,
            'thana' => 'Banani',
            'payment_method' => 'cod',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ];
    }

    // --- delivery rules -------------------------------------------------

    public function test_with_no_settings_row_the_calculator_uses_the_previous_fixed_rules(): void
    {
        $this->assertSame(0, SiteSetting::count());

        $this->assertSame(0, DeliveryFeeCalculator::forDivision('Dhaka', 2001));
        $this->assertSame(80, DeliveryFeeCalculator::forDivision('Dhaka', 2000));
        $this->assertSame(130, DeliveryFeeCalculator::forDivision('Chattogram', 99999));
    }

    public function test_a_freshly_created_settings_row_also_carries_the_previous_rules(): void
    {
        $settings = SiteSetting::create(['site_name' => 'Anaiza Nest']);

        $this->assertSame(2000, $settings->free_delivery_threshold);
        $this->assertSame(80, $settings->delivery_fee_dhaka);
        $this->assertSame(130, $settings->delivery_fee_outside_dhaka);
        $this->assertSame(7, $settings->return_window_days);
        $this->assertSame(0, DeliveryFeeCalculator::forDivision('Dhaka', 2001));
        $this->assertSame(80, DeliveryFeeCalculator::forDivision('Dhaka', 100));
    }

    public function test_edited_settings_change_the_calculator(): void
    {
        SiteSetting::create([
            'site_name' => 'Anaiza Nest',
            'free_delivery_threshold' => 5000,
            'delivery_fee_dhaka' => 100,
            'delivery_fee_outside_dhaka' => 200,
        ]);

        $this->assertSame(100, DeliveryFeeCalculator::forDivision('Dhaka', 2500));
        $this->assertSame(100, DeliveryFeeCalculator::forDivision('Dhaka', 5000), 'threshold itself is not free (strictly over)');
        $this->assertSame(0, DeliveryFeeCalculator::forDivision('Dhaka', 5001));
        $this->assertSame(200, DeliveryFeeCalculator::forDivision('Sylhet', 9000));
    }

    public function test_checkout_charges_the_edited_fee_end_to_end(): void
    {
        SiteSetting::create(['site_name' => 'Anaiza Nest', 'delivery_fee_dhaka' => 95, 'delivery_fee_outside_dhaka' => 175]);
        $product = $this->product(price: 500);

        $dhaka = $this->postJson('/api/orders', $this->orderPayload($product, 'Dhaka'));
        $outside = $this->postJson('/api/orders', array_merge($this->orderPayload($product, 'Rajshahi'), ['customer_phone' => '01710000001']));

        $this->assertSame(95, $dhaka->json('data.delivery_fee'));
        $this->assertSame(175, $outside->json('data.delivery_fee'));
    }

    public function test_site_settings_api_exposes_the_policy_and_follows_the_threshold_in_the_promo_text(): void
    {
        SiteSetting::create(['site_name' => 'Anaiza Nest', 'free_delivery_threshold' => 3500, 'brand_description' => 'A brand.']);

        $json = $this->getJson('/api/site-settings')->assertOk()->json('data');

        $this->assertSame(3500, $json['policy']['free_delivery_threshold']);
        $this->assertSame(['min' => 1, 'max' => 3], $json['policy']['delivery_days_dhaka']);
        $this->assertSame(['min' => 3, 'max' => 5], $json['policy']['delivery_days_outside_dhaka']);
        $this->assertSame(7, $json['policy']['return_window_days']);
        $this->assertSame('A brand.', $json['brand_description']);
        $this->assertStringContainsString('3,500', $json['promo_text']);
    }

    public function test_a_stored_copy_of_the_old_default_promo_text_follows_the_threshold(): void
    {
        SiteSetting::create([
            'site_name' => 'Anaiza Nest',
            'promo_text' => 'Free delivery inside Dhaka on orders over ৳2,000',
            'free_delivery_threshold' => 3000,
        ]);

        $this->assertStringContainsString('3,000', $this->getJson('/api/site-settings')->json('data.promo_text'));
    }

    public function test_a_customised_promo_text_is_never_overridden(): void
    {
        SiteSetting::create(['site_name' => 'Anaiza Nest', 'promo_text' => 'Eid sale is on', 'free_delivery_threshold' => 3000]);

        $this->assertSame('Eid sale is on', $this->getJson('/api/site-settings')->json('data.promo_text'));
    }

    public function test_brand_description_falls_back_to_the_brand_kit_boilerplate_when_blank(): void
    {
        SiteSetting::create(['site_name' => 'Anaiza Nest']);

        $this->assertSame(SiteSetting::defaultBrandDescription(), $this->getJson('/api/site-settings')->json('data.brand_description'));
    }

    // --- product content ------------------------------------------------

    public function test_product_api_exposes_summary_gtin_mpn_and_only_complete_spec_rows(): void
    {
        $this->product(overrides: [
            'summary' => 'Anaiza Nest Test Product is a test.',
            'gtin' => '1234567890123',
            'mpn' => 'MPN-1',
            'specifications' => [
                ['label' => 'Material', 'value' => 'Porcelain'],
                ['label' => 'Half row', 'value' => ''],
                ['label' => '', 'value' => 'orphan'],
                ['label' => 'Capacity', 'value' => '250 ml'],
            ],
        ]);

        $json = $this->getJson('/api/products/test-product')->assertOk()->json('data');

        $this->assertSame('Anaiza Nest Test Product is a test.', $json['summary']);
        $this->assertSame('1234567890123', $json['gtin']);
        $this->assertSame('MPN-1', $json['mpn']);
        $this->assertSame(
            [['label' => 'Material', 'value' => 'Porcelain'], ['label' => 'Capacity', 'value' => '250 ml']],
            $json['specifications'],
        );
    }

    public function test_product_without_the_optional_fields_returns_nulls_and_an_empty_table(): void
    {
        $this->product();

        $json = $this->getJson('/api/products/test-product')->assertOk()->json('data');

        $this->assertNull($json['gtin']);
        $this->assertNull($json['mpn']);
        $this->assertNull($json['summary']);
        $this->assertSame([], $json['specifications']);
    }

    // --- article --------------------------------------------------------

    public function test_article_api_exposes_author_updated_at_and_downgrades_h1(): void
    {
        Article::create([
            'title' => 'Care guide',
            'slug' => 'care-guide',
            'content' => '<h1>Inner title</h1><h2>First</h2><p>Body</p>',
            'author_name' => 'A. Writer',
            'author_bio' => 'Writes about tea.',
            'published_at' => now()->subDay(),
        ]);

        $json = $this->getJson('/api/articles/care-guide')->assertOk()->json('data');

        $this->assertSame(['name' => 'A. Writer', 'bio' => 'Writes about tea.'], $json['author']);
        $this->assertNotNull($json['updated_at']);
        $this->assertStringNotContainsString('<h1', $json['content']);
        $this->assertStringContainsString('<h2>Inner title</h2>', $json['content']);
    }

    public function test_category_intro_text_accepts_600_characters(): void
    {
        $intro = str_repeat('a', 600);
        Category::create(['name' => 'Long', 'slug' => 'long', 'intro_text' => $intro]);

        $this->assertSame($intro, Category::where('slug', 'long')->value('intro_text'));
    }
}
