<?php

namespace Tests\Feature\Api;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SiteSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Extra categories never leak into what search engines and ad platforms see: feeds and the sitemap use the PRIMARY category only, one row per product.
 */
class ProductCategoriesFeedsTest extends TestCase
{
    use RefreshDatabase;

    private SiteSetting $site;

    private Category $teaSets;

    private Category $gifts;

    private Category $ceramic;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->site = SiteSetting::create(['site_name' => 'Anaiza Nest', 'feed_storefront_url' => 'https://shop.example.test']);
        $this->site->feedToken();
        $this->teaSets = Category::create(['name' => 'Tea Sets', 'slug' => 'tea-sets', 'google_product_category' => 'Home & Garden > Kitchen & Dining > Tableware', 'product_type' => 'Tableware > Tea sets']);
        $this->gifts = Category::create(['name' => 'Gifts', 'slug' => 'gifts', 'google_product_category' => 'Arts & Entertainment > Party & Celebration > Gift Giving']);
        $this->ceramic = Category::create(['name' => 'Ceramic', 'slug' => 'ceramic']);
    }

    private function product(string $name, array $extras = []): Product
    {
        $product = Product::create([
            'category_id' => $this->teaSets->id, 'name' => $name, 'slug' => str($name)->slug()->toString(), 'price' => 4200, 'stock_quantity' => 5,
            'sku' => 'SKU-'.str($name)->slug(), 'is_active' => true, 'summary' => 'A ceramic tea set for two.',
        ]);
        $product->syncCategories($extras);

        return $product;
    }

    /** @return list<array<string, string>> */
    private function csvRows(string $file): array
    {
        $body = $this->get('/feeds/'.$file.'?token='.$this->site->fresh()->feed_token)->assertOk()->getContent();
        $handle = fopen('php://temp', 'w+');
        fwrite($handle, $body);
        rewind($handle);
        $header = fgetcsv($handle, 0, ',', '"', '');
        $rows = [];
        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            $rows[] = array_combine($header, $row);
        }

        return $rows;
    }

    public function test_a_product_in_three_categories_is_one_row_with_the_primary_categorys_values(): void
    {
        $this->product('Peacock Ceramic Tea Set', [$this->gifts->id, $this->ceramic->id]);

        foreach (['meta.csv', 'tiktok.csv'] as $file) {
            $rows = $this->csvRows($file);
            $this->assertCount(1, $rows, "{$file}: one row, not one per category");
        }

        $row = $this->csvRows('meta.csv')[0];
        $this->assertSame('Tableware > Tea sets', $row['product_type']);
        $this->assertSame('Home & Garden > Kitchen & Dining > Tableware', $row['google_product_category']);
        $this->assertSame('Tea Sets', $row['custom_label_0']);
        $this->assertStringNotContainsString('Gift', implode('|', [$row['product_type'], $row['google_product_category'], $row['custom_label_0']]));

        $xml = $this->get('/feeds/google.xml?token='.$this->site->fresh()->feed_token)->assertOk()->getContent();
        $this->assertSame(1, substr_count($xml, '<item>'), 'the Google feed has one item');
        $this->assertStringContainsString('Home &amp; Garden &gt; Kitchen &amp; Dining &gt; Tableware', $xml);
        $this->assertStringNotContainsString('Gift Giving', $xml);
    }

    public function test_variants_still_make_one_row_each_and_extras_do_not_multiply_them(): void
    {
        $product = $this->product('Peacock Ceramic Tea Set', [$this->gifts->id, $this->ceramic->id]);
        ProductVariant::create(['product_id' => $product->id, 'name' => 'Colour', 'value' => 'Blue', 'stock_quantity' => 3, 'sku' => 'PCT-BLU', 'price' => 4300]);
        ProductVariant::create(['product_id' => $product->id, 'name' => 'Colour', 'value' => 'Green', 'stock_quantity' => 3, 'sku' => 'PCT-GRN', 'price' => 4400]);

        $before = count($this->csvRows('meta.csv'));
        $product->syncCategories([]);
        Cache::flush();

        $this->assertSame($before, count($this->csvRows('meta.csv')), 'the same rows with or without extras');
        $this->assertCount(count(array_unique(array_column($this->csvRows('meta.csv'), 'id'))), $this->csvRows('meta.csv'), 'no id twice');
    }

    public function test_changing_only_the_primary_changes_the_feed_and_extras_never_do(): void
    {
        $product = $this->product('Peacock Ceramic Tea Set');
        $this->assertSame('Tea Sets', $this->csvRows('meta.csv')[0]['custom_label_0']);

        $product->syncCategories([$this->gifts->id]);
        Cache::flush();
        $this->assertSame('Tea Sets', $this->csvRows('meta.csv')[0]['custom_label_0']);

        $product->update(['category_id' => $this->gifts->id]);
        // The change itself drops the feed caches: no manual flush here.
        $this->assertSame('Gifts', $this->csvRows('meta.csv')[0]['custom_label_0']);
    }

    public function test_the_sitemap_lists_the_product_once_and_no_category_specific_urls(): void
    {
        $this->product('Peacock Ceramic Tea Set', [$this->gifts->id, $this->ceramic->id]);

        $sitemap = $this->getJson('/api/sitemap')->assertOk();

        $this->assertSame(['/product/peacock-ceramic-tea-set'], $sitemap->json('products.*.path'));
        $this->assertSame(['/category/tea-sets', '/category/gifts', '/category/ceramic'], $sitemap->json('categories.*.path'));
    }

    public function test_the_products_own_address_and_primary_category_are_unchanged_by_extras(): void
    {
        $product = $this->product('Peacock Ceramic Tea Set');
        $before = $this->getJson('/api/products/'.$product->slug)->json('data');

        $product->syncCategories([$this->gifts->id, $this->ceramic->id]);
        $after = $this->getJson('/api/products/'.$product->slug)->json('data');

        $this->assertSame($before['slug'], $after['slug']);
        $this->assertSame($before['category'], $after['category']);
        $this->assertSame($before['seo'], $after['seo']);
        unset($before['categories'], $after['categories']);
        $this->assertSame($before, $after, 'everything but the new `categories` list is identical');
    }
}
