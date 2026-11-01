<?php

namespace Tests\Feature\Api;

use App\Filament\Resources\CategoryResource\Pages\EditCategory;
use App\Models\Article;
use App\Models\Category;
use App\Models\LandingPage;
use App\Models\Page;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use App\Support\Seo;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Every public page answer carries seo: { title, description, image }, with the image a full https address that falls back to the picture the page
 * already has (a product's main image, then its category's; a category's thumbnail, then its banner; an article's cover).
 */
class SeoBlockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Uploaded pictures are served from the real shop address, which is https.
        config(['filesystems.disks.public.url' => 'https://api.anaizanest.example/storage']);
        Storage::forgetDisk('public');
    }

    private function category(array $attributes = []): Category
    {
        return Category::create($attributes + ['name' => 'Tea Sets', 'slug' => 'tea-sets']);
    }

    private function product(Category $category, array $attributes = []): Product
    {
        return Product::create($attributes + [
            'category_id' => $category->id, 'name' => 'Rose Cup', 'slug' => 'rose-cup', 'price' => 900, 'stock_quantity' => 5, 'sku' => 'RC-1', 'is_active' => true,
        ]);
    }

    private function seoOf(string $url): array
    {
        return $this->getJson($url)->assertOk()->json('data.seo');
    }

    // ---- products

    public function test_a_products_own_share_image_comes_first(): void
    {
        $product = $this->product($this->category(['thumbnail' => 'categories/t.webp']), ['og_image' => 'products/og/cup.jpg', 'meta_title' => 'A rose cup', 'meta_description' => 'Pretty.']);
        ProductImage::create(['product_id' => $product->id, 'url' => 'products/cup-main.webp', 'sort_order' => 0]);

        $seo = $this->seoOf('/api/products/rose-cup');

        $this->assertSame('A rose cup', $seo['title']);
        $this->assertSame('Pretty.', $seo['description']);
        $this->assertSame('https://api.anaizanest.example/storage/products/og/cup.jpg', $seo['image']);
    }

    public function test_without_one_the_main_picture_is_used_and_it_is_the_first_by_position(): void
    {
        $product = $this->product($this->category(['thumbnail' => 'categories/t.webp']));
        ProductImage::create(['product_id' => $product->id, 'url' => 'products/second.webp', 'sort_order' => 2]);
        ProductImage::create(['product_id' => $product->id, 'url' => 'products/first.webp', 'sort_order' => 1]);

        $seo = $this->seoOf('/api/products/rose-cup');

        $this->assertSame('Rose Cup', $seo['title'], 'the product name when no title was written');
        $this->assertSame('https://api.anaizanest.example/storage/products/first.webp', $seo['image']);
    }

    public function test_with_no_picture_at_all_the_category_picture_is_used_then_nothing(): void
    {
        $product = $this->product($this->category(['seo_image' => 'categories/seo/tea.jpg', 'thumbnail' => 'categories/t.webp']));
        $this->assertSame('https://api.anaizanest.example/storage/categories/seo/tea.jpg', $this->seoOf('/api/products/rose-cup')['image']);

        $product->category->update(['seo_image' => null]);
        $this->assertSame('https://api.anaizanest.example/storage/categories/t.webp', $this->seoOf('/api/products/rose-cup')['image']);

        $product->category->update(['thumbnail' => null]);
        $this->assertNull($this->seoOf('/api/products/rose-cup')['image'], 'null, never an empty or broken address: the storefront default applies');
    }

    public function test_the_older_keys_are_still_there(): void
    {
        $this->product($this->category(), ['og_image' => 'products/og/cup.jpg', 'meta_title' => 'T', 'meta_description' => 'D']);

        $seo = $this->seoOf('/api/products/rose-cup');

        $this->assertSame('T', $seo['meta_title']);
        $this->assertSame('D', $seo['meta_description']);
        $this->assertStringEndsWith('products/og/cup.jpg', $seo['og_image']);
    }

    public function test_the_product_list_answers_in_the_same_shape_without_extra_queries_per_product(): void
    {
        $category = $this->category();
        foreach (range(1, 6) as $n) {
            $product = $this->product($category, ['name' => "Cup {$n}", 'slug' => "cup-{$n}", 'sku' => "C-{$n}"]);
            ProductImage::create(['product_id' => $product->id, 'url' => "products/c{$n}.webp", 'sort_order' => 0]);
        }

        $this->getJson('/api/products')->assertOk();
        \DB::flushQueryLog();
        \DB::enableQueryLog();
        $rows = $this->getJson('/api/products')->assertOk()->json('data');
        $queries = count(\DB::getQueryLog());

        $this->assertCount(6, $rows);
        $this->assertStringStartsWith('https://', $rows[0]['seo']['image']);

        // Six more products add no queries.
        foreach (range(7, 12) as $n) {
            $product = $this->product($category, ['name' => "Cup {$n}", 'slug' => "cup-{$n}", 'sku' => "C-{$n}"]);
            ProductImage::create(['product_id' => $product->id, 'url' => "products/c{$n}.webp", 'sort_order' => 0]);
        }
        \DB::flushQueryLog();
        $this->getJson('/api/products')->assertOk();
        $this->assertSame($queries, count(\DB::getQueryLog()));
    }

    // ---- categories

    public function test_a_category_uses_its_share_image_then_thumbnail_then_banner(): void
    {
        $category = $this->category(['seo_image' => 'categories/seo/own.jpg', 'thumbnail' => 'categories/thumb.webp', 'banner_desktop' => 'categories/banner.webp', 'meta_title' => 'Tea sets for gifting']);

        $seo = $this->seoOf('/api/categories/tea-sets');
        $this->assertSame('Tea sets for gifting', $seo['title']);
        $this->assertSame('https://api.anaizanest.example/storage/categories/seo/own.jpg', $seo['image']);

        $category->update(['seo_image' => null]);
        $this->assertSame('https://api.anaizanest.example/storage/categories/thumb.webp', $this->seoOf('/api/categories/tea-sets')['image']);

        $category->update(['thumbnail' => null]);
        $this->assertSame('https://api.anaizanest.example/storage/categories/banner.webp', $this->seoOf('/api/categories/tea-sets')['image']);

        $category->update(['banner_desktop' => null, 'meta_title' => null]);
        $this->assertNull($this->seoOf('/api/categories/tea-sets')['image']);
        $this->assertSame('Tea Sets', $this->seoOf('/api/categories/tea-sets')['title'], 'the name when no title was written');
    }

    public function test_the_category_form_has_a_share_image_upload_and_existing_categories_keep_their_data(): void
    {
        $this->withoutExceptionHandling();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create(['role_id' => null]));
        $category = $this->category(['meta_title' => 'Kept', 'thumbnail' => 'categories/t.webp']);

        Livewire::test(EditCategory::class, ['record' => $category->getRouteKey()])->assertFormFieldExists('seo_image');

        $this->assertSame('Kept', $category->fresh()->meta_title);
        $this->assertNull($category->fresh()->seo_image, 'nothing was invented for an existing category');
    }

    // ---- pages, articles, landing pages

    public function test_a_page_an_article_and_a_landing_page_answer_in_the_same_shape(): void
    {
        Page::create(['title' => 'About', 'slug' => 'about', 'content' => '<p>x</p>', 'og_image' => 'http://cdn.example.com/about.jpg']);
        Article::create(['title' => 'Gift ideas', 'slug' => 'gift-ideas', 'content' => '<p>x</p>', 'published_at' => now()->subDay(), 'featured_image' => 'articles/cover.webp']);
        LandingPage::create(['title' => 'Eid offer', 'slug' => 'eid-offer', 'status' => 'published', 'og_image' => 'landing/og.jpg', 'blocks' => []]);

        $page = $this->seoOf('/api/pages/about');
        $this->assertSame('About', $page['title']);
        $this->assertSame('https://cdn.example.com/about.jpg', $page['image'], 'an address typed with http is served on https');

        $article = $this->seoOf('/api/articles/gift-ideas');
        $this->assertSame('Gift ideas', $article['title']);
        $this->assertSame('https://api.anaizanest.example/storage/articles/cover.webp', $article['image'], 'the cover when there is no share image');

        $landing = $this->seoOf('/api/landing-pages/eid-offer');
        $this->assertSame('Eid offer', $landing['title']);
        $this->assertSame('https://api.anaizanest.example/storage/landing/og.jpg', $landing['image']);
        $this->assertSame($landing['image'], $landing['og_image'], 'the older key says the same');
    }

    // ---- the address itself

    public function test_the_image_is_always_absolute_and_https_apart_from_a_machines_own_address(): void
    {
        config(['app.url' => 'https://api.anaizanest.example']);

        $this->assertSame('https://cdn.example.com/a.jpg', Seo::absolute('http://cdn.example.com/a.jpg'));
        $this->assertSame('https://cdn.example.com/a.jpg', Seo::absolute('//cdn.example.com/a.jpg'));
        $this->assertSame('http://localhost:8000/a.jpg', Seo::absolute('http://localhost:8000/a.jpg'));
        $this->assertSame('http://shop.test/a.jpg', Seo::absolute('http://shop.test/a.jpg'));
        $this->assertNull(Seo::absolute(null));
        $this->assertNull(Seo::absolute('   '));
    }

    public function test_the_first_usable_candidate_wins_and_blanks_are_skipped(): void
    {
        $this->assertSame('https://api.anaizanest.example/storage/b.jpg', Seo::image(null, '', 'b.jpg', 'c.jpg'));
        $this->assertNull(Seo::image(null, ''));
        $this->assertSame(['title' => null, 'description' => null, 'image' => null], Seo::block('', null));
    }
}
