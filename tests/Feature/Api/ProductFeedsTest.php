<?php

namespace Tests\Feature\Api;

use App\Http\Controllers\FeedController;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\SiteSetting;
use App\Support\Feeds\FeedBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductFeedsTest extends TestCase
{
    use RefreshDatabase;

    private SiteSetting $site;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Cache::flush();
        $this->site = SiteSetting::create(['site_name' => 'Anaiza Nest', 'feed_storefront_url' => 'https://shop.example.test/']);
        $this->site->feedToken();
        $this->category = Category::create(['name' => 'Tea Sets', 'slug' => 'tea-sets']);
    }

    private function url(string $file, array $query = []): string
    {
        return '/feeds/'.$file.'?'.http_build_query(array_merge(['token' => $this->site->fresh()->feed_token], $query));
    }

    private function product(array $attributes = []): Product
    {
        static $n = 0;
        $n++;

        return Product::create(array_merge([
            'category_id' => $this->category->id, 'name' => "Porcelain tea set {$n}", 'slug' => "tea-set-{$n}", 'price' => 1450, 'stock_quantity' => 10,
            'sku' => "TS-{$n}", 'is_active' => true, 'summary' => 'A porcelain tea set for two.',
        ], $attributes));
    }

    /** A real WebP file on the fake public disk, of the given size, attached to the product. */
    private function image(Product $product, int $width = 1200, int $height = 1200, int $order = 0): ProductImage
    {
        static $i = 0;
        $i++;
        $picture = imagecreatetruecolor($width, $height);
        imagefilledrectangle($picture, 0, 0, $width, $height, imagecolorallocate($picture, 200, 160, 120));
        $path = "products/generated/test-{$i}.webp";
        Storage::disk('public')->makeDirectory('products/generated');
        imagewebp($picture, Storage::disk('public')->path($path), 80);

        return $product->images()->create(['url' => $path, 'width' => $width, 'height' => $height, 'sort_order' => $order]);
    }

    /** @return list<\SimpleXMLElement> */
    private function googleItems(?string $xml = null): array
    {
        $xml ??= $this->get($this->url('google.xml'))->assertOk()->getContent();
        $feed = simplexml_load_string($xml);
        $this->assertNotFalse($feed, 'The Google feed is well-formed XML.');
        $items = [];
        foreach ($feed->channel->item as $item) {
            $items[] = $item;
        }

        return $items;
    }

    /** @return list<array<string, string>> */
    private function csvRows(string $file): array
    {
        $body = $this->get($this->url($file))->assertOk()->getContent();
        $lines = array_map('str_getcsv', array_filter(preg_split('/\r?\n/', $body) ?: []));
        // Rebuild with proper multi-line handling, since a quoted field may span lines.
        $handle = fopen('php://temp', 'w+');
        fwrite($handle, $body);
        rewind($handle);
        $header = fgetcsv($handle, 0, ',', '"', '');
        $rows = [];
        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            $rows[] = array_combine($header, $row);
        }
        $this->assertNotEmpty($lines);

        return $rows;
    }

    private function g(\SimpleXMLElement $item, string $name): string
    {
        return (string) $item->children('http://base.google.com/ns/1.0')->{$name};
    }

    // ---- access

    public function test_every_feed_needs_the_token(): void
    {
        foreach (['google.xml', 'meta.csv', 'tiktok.csv'] as $file) {
            $this->get("/feeds/{$file}")->assertNotFound();
            $this->get("/feeds/{$file}?token=wrong")->assertNotFound();
            $this->get("/feeds/{$file}?token=")->assertNotFound();
            $this->get($this->url($file))->assertOk();
        }
    }

    public function test_the_token_is_forty_random_characters_and_can_be_regenerated(): void
    {
        $old = $this->site->fresh()->feed_token;
        $this->assertSame(40, strlen($old));

        $new = $this->site->fresh()->regenerateFeedToken();

        $this->assertNotSame($old, $new);
        $this->get("/feeds/meta.csv?token={$old}")->assertNotFound();
        $this->get("/feeds/meta.csv?token={$new}")->assertOk();
    }

    public function test_the_feeds_have_the_right_types_and_can_be_downloaded(): void
    {
        $this->assertStringContainsString('application/xml', $this->get($this->url('google.xml'))->headers->get('Content-Type'));
        $this->assertStringContainsString('text/csv', $this->get($this->url('meta.csv'))->headers->get('Content-Type'));
        $this->assertStringContainsString('charset=UTF-8', $this->get($this->url('tiktok.csv'))->headers->get('Content-Type'));

        $this->get($this->url('meta.csv', ['download' => 1]))->assertHeader('Content-Disposition', 'attachment; filename="meta.csv"');
        $this->assertNull($this->get($this->url('meta.csv'))->headers->get('Content-Disposition'));
    }

    // ---- an empty catalogue

    public function test_an_empty_catalogue_gives_valid_empty_feeds(): void
    {
        $this->assertSame([], $this->googleItems());
        $feed = simplexml_load_string($this->get($this->url('google.xml'))->getContent());
        $this->assertSame('2.0', (string) $feed['version']);
        $this->assertStringContainsString('xmlns:g="http://base.google.com/ns/1.0"', $this->get($this->url('google.xml'))->getContent());

        $meta = trim($this->get($this->url('meta.csv'))->getContent());
        $this->assertStringStartsWith('id,title,description,availability,condition,price', $meta);
        $this->assertSame(1, count(explode("\n", $meta)), 'Header only.');

        $tiktok = trim($this->get($this->url('tiktok.csv'))->getContent());
        $this->assertStringStartsWith('sku_id,title,description,availability,condition,price', $tiktok);
        $this->assertSame(1, count(explode("\n", $tiktok)));
    }

    // ---- required fields

    public function test_every_item_in_every_feed_has_the_required_fields(): void
    {
        $brand = Brand::create(['name' => 'Anaiza', 'slug' => 'anaiza']);
        $a = $this->product(['brand_id' => $brand->id]);
        $this->image($a);
        $b = $this->product(['sku' => '']);
        $this->image($b);

        foreach ($this->googleItems() as $item) {
            foreach (['id', 'title', 'description', 'link', 'image_link', 'availability', 'price', 'condition', 'brand'] as $field) {
                $this->assertNotSame('', $this->g($item, $field), "google: {$field}");
            }
        }

        foreach (['meta.csv' => 'id', 'tiktok.csv' => 'sku_id'] as $file => $idColumn) {
            $rows = $this->csvRows($file);
            $this->assertCount(2, $rows);
            foreach ($rows as $row) {
                foreach ([$idColumn, 'title', 'description', 'availability', 'condition', 'price', 'link', 'image_link', 'brand'] as $column) {
                    $this->assertNotSame('', $row[$column], "{$file}: {$column}");
                }
                $this->assertSame('new', $row['condition']);
            }
        }
    }

    public function test_the_id_is_the_sku_or_else_the_product_id(): void
    {
        $withSku = $this->product(['sku' => 'TS-RED']);
        $without = $this->product(['sku' => '']);

        $ids = array_map(fn ($item) => $this->g($item, 'id'), $this->googleItems());

        $this->assertSame(['TS-RED', (string) $without->id], $ids);
        $this->assertSame([$withSku->sku, (string) $without->id], array_column($this->csvRows('tiktok.csv'), 'sku_id'));
    }

    // ---- prices and stock

    public function test_prices_are_a_number_a_space_and_the_currency(): void
    {
        $this->product(['price' => 1450]);

        $this->assertSame('1450.00 BDT', $this->g($this->googleItems()[0], 'price'));
        $this->assertSame('1450.00 BDT', $this->csvRows('meta.csv')[0]['price']);
        $this->assertSame('1450.00 BDT', $this->csvRows('tiktok.csv')[0]['price']);
    }

    public function test_a_sale_price_appears_only_when_it_is_lower_than_the_price(): void
    {
        $this->product(['price' => 1450, 'sale_price' => 1200]);
        $this->product(['price' => 1450, 'sale_price' => 1450]);
        $this->product(['price' => 1450, 'sale_price' => 1800]);
        $this->product(['price' => 1450, 'sale_price' => null]);

        $google = array_map(fn ($item) => $this->g($item, 'sale_price'), $this->googleItems());
        $this->assertSame(['1200.00 BDT', '', '', ''], $google);
        $this->assertSame(['1200.00 BDT', '', '', ''], array_column($this->csvRows('meta.csv'), 'sale_price'));
        $this->assertSame(['yes', 'no', 'no', 'no'], array_column($this->csvRows('meta.csv'), 'custom_label_1'));
    }

    public function test_availability_uses_each_platforms_words_and_out_of_stock_items_are_kept_by_default(): void
    {
        $this->product(['stock_quantity' => 5]);
        $this->product(['stock_quantity' => 0]);

        $this->assertSame(['in_stock', 'out_of_stock'], array_map(fn ($item) => $this->g($item, 'availability'), $this->googleItems()));
        $this->assertSame(['in stock', 'out of stock'], array_column($this->csvRows('meta.csv'), 'availability'));
        $this->assertSame(['in stock', 'out of stock'], array_column($this->csvRows('tiktok.csv'), 'availability'));
    }

    public function test_out_of_stock_items_can_be_left_out(): void
    {
        $this->site->update(['feeds_include_out_of_stock' => false]);
        $this->product(['stock_quantity' => 5]);
        $this->product(['stock_quantity' => 0]);
        Cache::flush();

        $this->assertCount(1, $this->googleItems());
    }

    public function test_inactive_excluded_and_unpriced_products_are_skipped(): void
    {
        $this->product(['is_active' => false]);
        $this->product(['exclude_from_feeds' => true]);
        $this->product(['price' => 0]);
        $listed = $this->product();

        $this->assertSame([$listed->sku], array_map(fn ($item) => $this->g($item, 'id'), $this->googleItems()));

        $health = collect((new FeedBuilder($this->site->fresh()))->build()['health'])->keyBy('name');
        $this->assertSame(['Inactive, so not listed'], $health[Product::find(1)->name]['reasons']);
        $this->assertSame(['Switched off for feeds'], $health[Product::find(2)->name]['reasons']);
        $this->assertSame(['No price'], $health[Product::find(3)->name]['reasons']);
    }

    // ---- text

    public function test_titles_and_descriptions_are_plain_text_and_titles_have_no_double_quotes(): void
    {
        $this->product([
            'name' => 'The "Rose" <b>tea</b> set &amp; cups',
            'summary' => null,
            'short_description' => null,
            'description' => '<h2>Why</h2><p>Made by hand.</p><p>Fires at 1,200&deg;C.</p>',
        ]);

        $item = $this->googleItems()[0];
        $this->assertSame('The Rose tea set & cups', $this->g($item, 'title'));
        $this->assertSame('Why Made by hand. Fires at 1,200°C.', $this->g($item, 'description'));
        $this->assertSame('The Rose tea set & cups', $this->csvRows('meta.csv')[0]['title']);
    }

    public function test_long_titles_and_descriptions_are_cut_to_the_limits(): void
    {
        $this->product(['name' => str_repeat('Tea ', 80), 'summary' => str_repeat('word ', 2000)]);

        $item = $this->googleItems()[0];
        $this->assertLessThanOrEqual(150, mb_strlen($this->g($item, 'title')));
        $this->assertLessThanOrEqual(5000, mb_strlen($this->g($item, 'description')));
    }

    public function test_csv_fields_with_commas_quotes_and_line_breaks_survive_a_round_trip(): void
    {
        $this->product(['summary' => "Cups, saucers and a \"pot\".\nSecond line."]);

        $description = $this->csvRows('meta.csv')[0]['description'];

        $this->assertSame('Cups, saucers and a "pot". Second line.', $description);
        $this->assertStringContainsString('"Cups, saucers and a ""pot"". Second line."', $this->get($this->url('meta.csv'))->getContent());
    }

    // ---- links, brand, identifiers, categories

    public function test_the_link_uses_the_feed_storefront_url_then_frontend_url(): void
    {
        $product = $this->product();

        $this->assertSame("https://shop.example.test/product/{$product->slug}", $this->g($this->googleItems()[0], 'link'));

        $this->site->update(['feed_storefront_url' => null]);
        Cache::flush();
        config(['app.frontend_url' => 'https://new.example.test']);
        $this->assertSame("https://new.example.test/product/{$product->slug}", $this->csvRows('meta.csv')[0]['link']);
    }

    public function test_brand_is_the_products_brand_otherwise_the_site_name(): void
    {
        $this->product(['brand_id' => Brand::create(['name' => 'Anaiza Studio', 'slug' => 'studio'])->id]);
        $this->product();

        $this->assertSame(['Anaiza Studio', 'Anaiza Nest'], array_map(fn ($item) => $this->g($item, 'brand'), $this->googleItems()));
    }

    public function test_gtin_and_mpn_are_sent_when_present_and_google_is_told_when_there_are_none(): void
    {
        $this->product(['gtin' => '8 901234 567890', 'mpn' => 'PTS-001']);
        $this->product();

        [$with, $without] = $this->googleItems();
        $this->assertSame('8901234567890', $this->g($with, 'gtin'));
        $this->assertSame('PTS-001', $this->g($with, 'mpn'));
        $this->assertSame('', $this->g($with, 'identifier_exists'));
        $this->assertSame('no', $this->g($without, 'identifier_exists'));
        $this->assertSame('', $this->g($without, 'gtin'));

        $rows = $this->csvRows('meta.csv');
        $this->assertSame(['8901234567890', ''], array_column($rows, 'gtin'));
        $this->assertSame(['PTS-001', ''], array_column($rows, 'mpn'));
    }

    public function test_product_type_defaults_to_the_category_and_labels_are_filled(): void
    {
        $this->category->update(['google_product_category' => 'Home & Garden > Kitchen & Dining > Tableware']);
        $this->product(['is_featured' => true]);

        $row = $this->csvRows('meta.csv')[0];
        $this->assertSame('Tea Sets', $row['product_type']);
        $this->assertSame('Home & Garden > Kitchen & Dining > Tableware', $row['google_product_category']);
        $this->assertSame(['Tea Sets', 'no', 'yes'], [$row['custom_label_0'], $row['custom_label_1'], $row['custom_label_2']]);

        $this->category->update(['product_type' => 'Tableware > Tea sets']);
        Cache::flush();
        $this->assertSame('Tableware > Tea sets', $this->csvRows('meta.csv')[0]['product_type']);
    }

    // ---- images: JPEG only

    public function test_feed_images_are_jpeg_renditions_and_never_webp(): void
    {
        $product = $this->product();
        $this->image($product, 800, 600, 0);
        $this->image($product, 400, 400, 1);

        $google = $this->get($this->url('google.xml'))->getContent();
        $this->assertStringNotContainsString('.webp', $google);
        $item = $this->googleItems($google)[0];
        $main = $this->g($item, 'image_link');
        $this->assertStringEndsWith('.jpg', $main);
        $additional = (array) $item->children('http://base.google.com/ns/1.0')->additional_image_link;
        $this->assertCount(1, $additional);

        $this->assertStringNotContainsString('.webp', $this->get($this->url('meta.csv'))->getContent());
        $this->assertStringNotContainsString('.webp', $this->get($this->url('tiktok.csv'))->getContent());

        $path = 'feeds/images/'.basename($main);
        Storage::disk('public')->assertExists($path);
        [$w, $h, $type] = getimagesizefromstring(Storage::disk('public')->get($path));
        $this->assertSame(IMAGETYPE_JPEG, $type);
        $this->assertGreaterThanOrEqual(1024, max($w, $h), 'Small sources are scaled up to 1024px on the long side.');
        $this->assertLessThanOrEqual(8 * 1024 * 1024, Storage::disk('public')->size($path));
    }

    public function test_a_large_image_is_capped_and_renditions_are_made_once(): void
    {
        $product = $this->product();
        $this->image($product, 1200, 3000);

        $first = $this->g($this->googleItems()[0], 'image_link');
        $path = 'feeds/images/'.basename($first);
        [, $height] = getimagesizefromstring(Storage::disk('public')->get($path));
        $this->assertSame(2000, $height);

        $modified = Storage::disk('public')->lastModified($path);
        Cache::flush();
        $this->assertSame($first, $this->g($this->googleItems()[0], 'image_link'));
        $this->assertSame($modified, Storage::disk('public')->lastModified($path));
    }

    public function test_at_most_ten_additional_images_are_listed(): void
    {
        $product = $this->product();
        foreach (range(0, 12) as $i) {
            $this->image($product, 600, 600, $i);
        }

        $additional = $this->csvRows('meta.csv')[0]['additional_image_link'];

        $this->assertCount(10, explode(',', $additional));
    }

    public function test_an_external_webp_is_never_listed_but_an_external_jpeg_is(): void
    {
        $product = $this->product();
        $product->images()->create(['url' => 'https://cdn.example.test/a.webp', 'sort_order' => 0]);
        $product->images()->create(['url' => 'https://cdn.example.test/b.jpg', 'sort_order' => 1]);

        $this->assertSame('https://cdn.example.test/b.jpg', $this->csvRows('meta.csv')[0]['image_link']);
    }

    // ---- variants

    public function test_a_variant_with_its_own_sku_is_its_own_item_grouped_with_its_product(): void
    {
        $product = $this->product(['sku' => 'TS-1', 'price' => 1450]);
        $product->variants()->create(['name' => 'Colour', 'value' => 'Red', 'sku' => 'TS-1-R', 'stock_quantity' => 3]);
        $product->variants()->create(['name' => 'Colour', 'value' => 'Blue', 'sku' => 'TS-1-B', 'price' => 1600, 'sale_price' => 1500, 'stock_quantity' => 0]);
        $product->variants()->create(['name' => 'Gift box', 'value' => 'Yes', 'sku' => null]);
        $plain = $this->product(['sku' => 'MUG-1']);

        $items = $this->googleItems();

        $this->assertSame(['TS-1', 'TS-1-R', 'TS-1-B', 'MUG-1'], array_map(fn ($item) => $this->g($item, 'id'), $items));
        $this->assertSame([(string) $product->id, (string) $product->id, (string) $product->id, ''], array_map(fn ($item) => $this->g($item, 'item_group_id'), $items));
        $this->assertSame($product->name.' - Colour: Red', $this->g($items[1], 'title'));
        $this->assertSame('1600.00 BDT', $this->g($items[2], 'price'));
        $this->assertSame('1500.00 BDT', $this->g($items[2], 'sale_price'));
        $this->assertSame('out_of_stock', $this->g($items[2], 'availability'));
        $this->assertSame('1450.00 BDT', $this->g($items[1], 'price'), 'A variant with no price of its own takes the product price.');

        $meta = $this->csvRows('meta.csv');
        $this->assertSame(['TS-1', 'TS-1-R', 'TS-1-B', 'MUG-1'], array_column($meta, 'id'));
        $this->assertSame([(string) $product->id, (string) $product->id, (string) $product->id, ''], array_column($meta, 'item_group_id'));
        $this->assertNotNull($plain);
    }

    public function test_tiktok_leaves_out_a_product_that_has_variant_items(): void
    {
        $product = $this->product(['sku' => 'TS-1']);
        $product->variants()->create(['name' => 'Colour', 'value' => 'Red', 'sku' => 'TS-1-R', 'stock_quantity' => 3]);
        $this->product(['sku' => 'MUG-1']);

        $this->assertSame(['TS-1-R', 'MUG-1'], array_column($this->csvRows('tiktok.csv'), 'sku_id'));
        $this->assertSame(['TS-1', 'TS-1-R', 'MUG-1'], array_column($this->csvRows('meta.csv'), 'id'));
    }

    public function test_a_variant_uses_its_own_image_first(): void
    {
        $product = $this->product(['sku' => 'TS-1']);
        $this->image($product, 900, 900, 0);
        $own = $this->image($product, 900, 900, 1);
        $product->variants()->create(['name' => 'Colour', 'value' => 'Red', 'sku' => 'TS-1-R', 'image_id' => $own->id]);

        [, $variant] = $this->googleItems();

        $this->assertStringContainsString('/'.$own->id.'-', $this->g($variant, 'image_link'));
    }

    public function test_two_items_never_share_an_id(): void
    {
        // SKUs are unique per product, but a variant's SKU could still be typed the same as another product's.
        $this->product(['sku' => 'SAME']);
        $this->product(['sku' => 'OTHER'])->variants()->create(['name' => 'Colour', 'value' => 'Red', 'sku' => 'SAME']);

        $this->assertSame(['SAME', 'OTHER'], array_map(fn ($item) => $this->g($item, 'id'), $this->googleItems()));
        $health = (new FeedBuilder($this->site->fresh()))->build()['health'];
        $this->assertTrue(collect($health)->pluck('reasons')->flatten()->contains(fn ($reason) => str_contains($reason, 'already used')));
    }

    // ---- caching and health

    public function test_a_feed_is_kept_for_an_hour_and_can_be_rebuilt(): void
    {
        $this->product(['name' => 'First name']);
        $this->assertSame('First name', $this->g($this->googleItems()[0], 'title'));

        Product::query()->update(['name' => 'Second name']);
        $this->assertSame('First name', $this->g($this->googleItems()[0], 'title'), 'Served from the hour-long cache.');

        FeedController::forgetAll();
        $this->assertSame('Second name', $this->g($this->googleItems()[0], 'title'));
    }

    public function test_the_health_report_explains_warnings_without_blocking_anything(): void
    {
        $noImage = $this->product(['name' => 'No picture', 'summary' => null]);
        $small = $this->product(['name' => 'Small picture']);
        $this->image($small, 300, 300);
        $long = $this->product(['name' => str_repeat('Long ', 50)]);
        $this->image($long);

        $result = (new FeedBuilder($this->site->fresh()))->build();
        $health = collect($result['health'])->keyBy('product_id');

        $this->assertCount(3, $result['items'], 'Every product is still listed.');
        $this->assertContains('No usable image (JPEG or PNG), so the item has none', $health[$noImage->id]['reasons']);
        $this->assertContains('No description, so the title is used', $health[$noImage->id]['reasons']);
        $this->assertContains('No brand, so the shop name is used', $health[$noImage->id]['reasons']);
        $this->assertContains('An image is under 500px, which Meta and TikTok do not accept', $health[$small->id]['reasons']);
        $this->assertContains('Title is longer than 150 characters, so it is cut', $health[$long->id]['reasons']);
        $this->assertSame('Warning', $health[$noImage->id]['status']);
    }
}
