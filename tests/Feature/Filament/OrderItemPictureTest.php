<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\OrderResource\Pages\EditOrder;
use App\Filament\Resources\OrderResource\RelationManagers\ItemsRelationManager;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Support\OrderItemPicture;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\CourierFixtures;
use Tests\TestCase;

/**
 * The small picture beside each line on the admin order page: saved picture, else the variant's, else the product's main picture, else a neutral
 * tile (also for a product deleted since). Reading only: nothing is made or saved while the page is open.
 */
class OrderItemPictureTest extends TestCase
{
    use CourierFixtures;
    use RefreshDatabase;

    private Product $product;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->withoutExceptionHandling();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->admin());
        $this->shop();

        $category = Category::create(['name' => 'Mugs', 'slug' => 'mugs']);
        $this->product = Product::create(['category_id' => $category->id, 'name' => 'Rose Mug', 'slug' => 'rose-mug', 'price' => 900, 'stock_quantity' => 5, 'sku' => 'RM-1', 'is_active' => true]);
        $this->order = $this->codOrder();
    }

    private function image(string $name, int $sort): ProductImage
    {
        return ProductImage::create(['product_id' => $this->product->id, 'url' => "products/{$name}.webp", 'url_400' => "products/{$name}-400.webp", 'sort_order' => $sort]);
    }

    private function line(array $attributes = []): OrderItem
    {
        return OrderItem::create($attributes + [
            'order_id' => $this->order->id, 'product_id' => $this->product->id, 'product_name' => 'Rose Mug', 'quantity' => 1, 'price' => 900,
        ]);
    }

    private function url(OrderItem $item): ?string
    {
        return OrderItemPicture::url($item->fresh(['product.images', 'product.variants.image']));
    }

    public function test_a_variants_own_picture_comes_before_the_products(): void
    {
        $this->image('main', 0);
        $blue = $this->image('blue', 3);
        ProductVariant::create(['product_id' => $this->product->id, 'name' => 'Colour', 'value' => 'Blue', 'price' => 900, 'stock_quantity' => 3, 'sku' => 'RM-B', 'image_id' => $blue->id]);
        ProductVariant::create(['product_id' => $this->product->id, 'name' => 'Colour', 'value' => 'Red', 'price' => 900, 'stock_quantity' => 3, 'sku' => 'RM-R']);

        $this->assertStringEndsWith('products/blue-400.webp', $this->url($this->line(['variant_name' => 'Colour', 'variant_value' => 'Blue'])));
        $this->assertStringEndsWith('products/main-400.webp', $this->url($this->line(['variant_name' => 'Colour', 'variant_value' => 'Red'])), 'a variant with no picture of its own: the product\'s');
    }

    public function test_the_product_s_first_picture_by_position_is_the_fallback_and_it_is_the_small_size(): void
    {
        $this->image('second', 2);
        $this->image('first', 1);

        $url = $this->url($this->line());

        $this->assertStringEndsWith('products/first-400.webp', $url);
        $this->assertStringNotContainsString('products/first.webp', $url, 'never the full-size file');
    }

    public function test_the_picture_the_line_saved_when_it_was_ordered_comes_first(): void
    {
        $this->image('main', 0);
        Storage::disk('public')->put('email-thumbs/abc-128.jpg', 'x');

        $this->assertStringEndsWith('email-thumbs/abc-128.jpg', $this->url($this->line(['image_path' => 'email-thumbs/abc-128.jpg'])));
        $this->assertStringEndsWith('products/main-400.webp', $this->url($this->line(['image_path' => 'email-thumbs/gone.jpg'])), 'a saved picture whose file has gone: the next one down');
    }

    public function test_no_picture_anywhere_is_null_and_the_page_shows_the_neutral_tile(): void
    {
        $item = $this->line();

        $this->assertNull($this->url($item));
        $this->assertStringStartsWith('data:image/svg+xml;base64,', OrderItemPicture::placeholder());
        $this->assertStringContainsString('<svg', base64_decode(substr(OrderItemPicture::placeholder(), strlen('data:image/svg+xml;base64,'))));
    }

    public function test_a_product_deleted_since_leaves_the_saved_picture_or_the_tile(): void
    {
        $this->image('main', 0);
        Storage::disk('public')->put('email-thumbs/abc-128.jpg', 'x');
        $withSaved = $this->line(['image_path' => 'email-thumbs/abc-128.jpg']);
        $without = $this->line();

        DB::table('product_images')->where('product_id', $this->product->id)->delete();
        $this->product->delete();

        $this->assertNull($without->fresh()->product_id, 'the line keeps its name and price, the link is gone');
        $this->assertStringEndsWith('email-thumbs/abc-128.jpg', $this->url($withSaved));
        $this->assertNull($this->url($without));
    }

    public function test_the_items_table_shows_a_lazy_48px_square_picture_that_opens_the_product(): void
    {
        $this->image('main', 0);
        $item = $this->line();
        $orphan = $this->line(['product_id' => null, 'product_name' => 'Gone piece']);

        $table = Livewire::test(ItemsRelationManager::class, ['ownerRecord' => $this->order, 'pageClass' => EditOrder::class])
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$item, $orphan])
            ->assertTableColumnExists('picture')
            ->assertSeeHtml('loading="lazy"')
            ->assertSeeHtml('products/main-400.webp')
            ->assertSeeHtml('data:image/svg+xml;base64,');

        $html = $table->html();
        $this->assertStringContainsString('border-radius:2px', $html);
        $this->assertStringContainsString('height: 48px', $html);
        $this->assertStringContainsString('width: 48px', $html);
        $this->assertStringContainsString(route('filament.admin.resources.products.edit', $this->product), $html, 'the picture opens the product');
        $this->assertStringNotContainsString('box-shadow:0', $html);
    }

    public function test_opening_the_page_writes_nothing(): void
    {
        $this->image('main', 0);
        $item = $this->line();

        Livewire::test(ItemsRelationManager::class, ['ownerRecord' => $this->order, 'pageClass' => EditOrder::class])->assertSuccessful();

        $this->assertNull($item->fresh()->image_path, 'no thumbnail is made or saved by looking at the page');
        $this->assertSame([], Storage::disk('public')->allFiles('email-thumbs'));
    }

    public function test_the_table_costs_the_same_queries_for_one_line_or_many(): void
    {
        $this->image('main', 0);
        $this->line();
        $queries = fn (): int => $this->countQueries(fn () => Livewire::test(ItemsRelationManager::class, ['ownerRecord' => $this->order, 'pageClass' => EditOrder::class]));

        $one = $queries();
        foreach (range(1, 6) as $n) {
            $this->line(['product_name' => "Line {$n}"]);
        }

        $this->assertSame($one, $queries());
    }

    private function countQueries(callable $run): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $run();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
