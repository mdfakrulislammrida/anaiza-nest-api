<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\ProductResource\Pages\ListProducts;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Support\ProductThumbnail;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\CourierFixtures;
use Tests\TestCase;

/**
 * The small picture on the admin Products list (OPS3-B P1). The list had no picture column: a product's pictures live in its gallery (product_images), which
 * the list never read. The picture is the main one, else the rest of the gallery, else a variant's, else a neutral tile, smallest size first, and a file that
 * is gone from the disk is skipped.
 */
class ProductThumbnailTest extends TestCase
{
    use CourierFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->withoutExceptionHandling();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->shop();
        $this->actingAs($this->admin());
    }

    private function product(string $name = 'Rose cup'): Product
    {
        $category = Category::firstOrCreate(['slug' => 'cups'], ['name' => 'Cups']);

        return Product::create(['category_id' => $category->id, 'name' => $name, 'slug' => str($name)->slug()->toString(), 'price' => 900, 'stock_quantity' => 5, 'sku' => strtoupper(substr(md5($name), 0, 6)), 'is_active' => true]);
    }

    /** A gallery picture whose copies exist on the disk. */
    private function picture(Product $product, string $name, int $sort, bool $files = true, array $extra = []): ProductImage
    {
        if ($files) {
            foreach (["{$name}-400.webp", "{$name}-800.webp", "{$name}.webp"] as $file) {
                Storage::disk('public')->put("products/{$file}", 'x');
            }
        }

        return ProductImage::create($extra + ['product_id' => $product->id, 'url' => "products/{$name}.webp", 'url_400' => "products/{$name}-400.webp", 'url_800' => "products/{$name}-800.webp", 'sort_order' => $sort]);
    }

    private function thumb(Product $product): ?string
    {
        return ProductThumbnail::url(Product::with(['images', 'variants.image'])->find($product->id));
    }

    public function test_the_main_picture_comes_first_in_its_smallest_size(): void
    {
        $product = $this->product();
        $this->picture($product, 'second', 2);
        $this->picture($product, 'main', 1);

        $this->assertStringEndsWith('products/main-400.webp', $this->thumb($product));
    }

    public function test_a_main_picture_whose_file_is_gone_falls_back_to_its_other_size_then_to_the_next_picture(): void
    {
        $product = $this->product();
        $main = $this->picture($product, 'main', 1);
        $this->picture($product, 'gallery', 2);

        Storage::disk('public')->delete('products/main-400.webp');
        $this->assertStringEndsWith('products/main-800.webp', $this->thumb($product), 'the next size of the same picture');

        Storage::disk('public')->delete(['products/main-800.webp', 'products/main.webp']);
        $this->assertStringEndsWith('products/gallery-400.webp', $this->thumb($product), 'the rest of the gallery');
        $this->assertNotNull($main);
    }

    public function test_with_no_gallery_a_variants_picture_is_used(): void
    {
        $product = $this->product();
        $image = $this->picture($product, 'blue', 1);
        ProductVariant::create(['product_id' => $product->id, 'name' => 'Colour', 'value' => 'Blue', 'price' => 900, 'stock_quantity' => 2, 'sku' => 'RC-B', 'image_id' => $image->id]);
        // The gallery picture is gone from the disk, but the variant points at a picture that exists.
        $gone = $this->picture($product, 'gone', 0, files: false);
        $this->assertNotNull($gone);

        $this->assertStringEndsWith('products/blue-400.webp', $this->thumb($product));
    }

    public function test_nothing_at_all_is_null_and_another_servers_address_is_used_as_it_is(): void
    {
        $product = $this->product('Plain mug');
        $this->assertNull($this->thumb($product));

        $this->picture($product, 'ext', 1, files: false, extra: ['url' => 'https://cdn.example.com/mug.jpg', 'url_400' => null, 'url_800' => null]);
        $this->assertSame('https://cdn.example.com/mug.jpg', $this->thumb($product));
    }

    public function test_the_list_shows_a_small_lazy_square_picture_or_the_neutral_tile_and_survives_a_missing_file(): void
    {
        $with = $this->product('With picture');
        $this->picture($with, 'wp', 1);
        $without = $this->product('Without picture');
        $broken = $this->product('Broken picture');
        $this->picture($broken, 'bp', 1, files: false);

        $html = Livewire::test(ListProducts::class)->assertSuccessful()->assertCanSeeTableRecords([$with, $without, $broken])->html();

        $this->assertStringContainsString('products/wp-400.webp', $html);
        $this->assertStringNotContainsString('products/wp.webp', $html, 'never the full-size file');
        $this->assertStringContainsString('loading="lazy"', $html);
        $this->assertStringContainsString('border-radius:2px', $html);
        $this->assertStringContainsString('height: 48px', $html);
        $this->assertStringContainsString('data:image/svg+xml;base64,', $html, 'the neutral tile');
        $this->assertStringNotContainsString('products/bp', $html, 'a picture that is not on the disk is not linked');
    }

    public function test_the_thumbnail_is_a_column_beside_the_others_and_the_phone_card_uses_it(): void
    {
        $product = $this->product();
        $this->picture($product, 'main', 1);

        $columns = Livewire::test(ListProducts::class)->instance()->getTable()->getColumns();

        $this->assertArrayHasKey('thumbnail', $columns);
        $this->assertSame('md', $columns['thumbnail']->getVisibleFrom(), 'a desktop column; the phone card draws the picture itself');
        $this->assertStringContainsString('am-picture', Livewire::test(ListProducts::class)->html());
    }

    public function test_the_list_costs_the_same_queries_for_few_or_many_products(): void
    {
        $this->picture($this->product('One'), 'one', 1);
        $count = function (): int {
            \DB::flushQueryLog();
            \DB::enableQueryLog();
            Livewire::test(ListProducts::class);

            return count(\DB::getQueryLog());
        };

        $few = $count();
        foreach (range(1, 6) as $n) {
            $this->picture($this->product("More {$n}"), "more{$n}", 1);
        }

        $this->assertSame($few, $count());
    }
}
