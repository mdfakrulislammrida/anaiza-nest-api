<?php

namespace Tests\Feature\Api;

use App\Filament\Resources\CategoryResource\Pages\EditCategory;
use App\Filament\Resources\HomepageSectionResource\Pages\EditHomepageSection;
use App\Models\Category;
use App\Models\HomepageSection;
use App\Models\LandingPage;
use App\Models\Page;
use App\Models\Product;
use App\Models\SiteSetting;
use App\Models\SlugRedirect;
use App\Models\User;
use App\Support\Slugs;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Categories, pages and landing pages (the pages an occasion is built from) can be given a new address without breaking the old one: the old address is remembered, answers
 * with the page and a redirect hint, and the homepage tiles, the sitemap and the product feeds all use the current address. A new address is checked in plain words.
 * (There is no Occasion model: "Shop by occasion" tiles are items of a homepage section, linking to a category, a page, the gift finder or a custom address.)
 */
class SlugRedirectsTest extends TestCase
{
    use RefreshDatabase;

    private function category(string $slug = 'eid-gifts', string $name = 'Eid gifts'): Category
    {
        return Category::create(['name' => $name, 'slug' => $slug]);
    }

    // ---- the rule

    public function test_the_address_rule_in_every_case(): void
    {
        $this->category('eid-gifts');

        foreach ([
            ['wedding-gifts', null],
            ['a1-b2', null],
            ['Wedding', 'lower-case'],
            ['wedding gifts', 'lower-case'],
            ['wedding_gifts', 'lower-case'],
            ['-wedding', 'lower-case'],
            ['wedding-', 'lower-case'],
            ['wedding--gifts', 'lower-case'],
            ['উৎসব', 'lower-case'],
            ['a/b', 'lower-case'],
            ['', 'lower-case'],
            ['shop', 'already uses'],
            ['cart', 'already uses'],
            ['checkout', 'already uses'],
            ['account', 'already uses'],
            ['track-order', 'already uses'],
            ['category', 'already uses'],
            ['product', 'already uses'],
            ['search', 'already uses'],
            ['occasion', 'already uses'],
            ['eid-gifts', 'Another page already has the address'],
        ] as [$slug, $expected]) {
            $problem = Slugs::problem('category', $slug);

            $expected === null ? $this->assertNull($problem, $slug) : $this->assertStringContainsString($expected, (string) $problem, $slug);
        }
    }

    public function test_a_record_may_keep_its_own_address_and_not_take_another_ones_old_one(): void
    {
        $eid = $this->category('eid-gifts');
        $other = $this->category('wedding', 'Wedding');
        $other->update(['slug' => 'wedding-gifts']);   // "wedding" is now an old address of that page

        $this->assertNull(Slugs::problem('category', 'eid-gifts', $eid->id));
        $this->assertStringContainsString('old address of another page', (string) Slugs::problem('category', 'wedding', $eid->id));
        $this->assertNull(Slugs::problem('category', 'wedding', $other->id), 'its own old address can be taken back');
    }

    // ---- a new address in the admin

    public function test_the_admin_form_refuses_a_duplicate_a_reserved_word_and_a_bad_address_with_a_message(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create(['role_id' => null]));
        $this->category('wedding', 'Wedding');
        $eid = $this->category('eid-gifts');

        foreach (['wedding', 'shop', 'Eid Gifts!'] as $bad) {
            Livewire::test(EditCategory::class, ['record' => $eid->getRouteKey()])->fillForm(['slug' => $bad])->call('save')->assertHasFormErrors(['slug']);
            $this->assertSame('eid-gifts', $eid->fresh()->slug);
        }

        Livewire::test(EditCategory::class, ['record' => $eid->getRouteKey()])->fillForm(['slug' => 'eid-special'])->call('save')->assertHasNoFormErrors();
        $this->assertSame('eid-special', $eid->fresh()->slug);
        $this->assertSame(['eid-gifts'], $eid->fresh()->previousSlugs());
    }

    // ---- the old address keeps working

    public function test_a_category_with_a_new_address_answers_the_old_one_with_a_redirect_hint_and_the_new_one_without(): void
    {
        $category = $this->category('eid-gifts');
        $category->update(['slug' => 'eid-special']);

        $new = $this->getJson('/api/categories/eid-special')->assertOk();
        $new->assertJsonPath('data.slug', 'eid-special')->assertJsonPath('data.path', '/category/eid-special')->assertJsonPath('data.previous_slugs', ['eid-gifts']);
        $this->assertNull($new->json('redirect'));

        $old = $this->getJson('/api/categories/eid-gifts')->assertOk();
        $old->assertJsonPath('data.slug', 'eid-special')->assertJsonPath('redirect', ['from' => '/category/eid-gifts', 'to' => '/category/eid-special', 'status' => 301]);

        $this->getJson('/api/categories/never-existed')->assertNotFound();
    }

    public function test_every_old_address_is_kept_and_going_back_ends_the_redirect(): void
    {
        $category = $this->category('a-gifts');
        $category->update(['slug' => 'b-gifts']);
        $category->update(['slug' => 'c-gifts']);

        $this->getJson('/api/categories/a-gifts')->assertOk()->assertJsonPath('redirect.to', '/category/c-gifts');
        $this->getJson('/api/categories/b-gifts')->assertOk()->assertJsonPath('redirect.to', '/category/c-gifts');
        $this->assertSame(['a-gifts', 'b-gifts'], $category->fresh()->previousSlugs());

        $category->update(['slug' => 'a-gifts']);

        $this->assertNull($this->getJson('/api/categories/a-gifts')->assertOk()->json('redirect'));
        $this->assertSame(['b-gifts', 'c-gifts'], $category->fresh()->previousSlugs());
    }

    public function test_deleting_a_page_forgets_its_old_addresses(): void
    {
        $category = $this->category('a-gifts');
        $category->update(['slug' => 'b-gifts']);

        $category->delete();

        $this->assertSame(0, SlugRedirect::query()->count());
        $this->getJson('/api/categories/a-gifts')->assertNotFound();
    }

    public function test_a_page_and_a_landing_page_do_the_same(): void
    {
        $page = Page::create(['title' => 'Eid guide', 'slug' => 'eid-guide', 'content' => '<p>Hi</p>']);
        $page->update(['slug' => 'eid-gift-guide']);
        $this->getJson('/api/pages/eid-guide')->assertOk()->assertJsonPath('data.slug', 'eid-gift-guide')->assertJsonPath('data.path', '/pages/eid-gift-guide')->assertJsonPath('redirect.to', '/pages/eid-gift-guide');
        $this->assertNull($this->getJson('/api/pages/eid-gift-guide')->json('redirect'));

        $landing = LandingPage::create(['title' => 'Eid sale', 'slug' => 'eid-sale', 'status' => LandingPage::PUBLISHED, 'layout' => LandingPage::NORMAL, 'blocks' => []]);
        $landing->update(['slug' => 'eid-offer']);
        $this->getJson('/api/landing-pages/eid-sale')->assertOk()->assertJsonPath('data.slug', 'eid-offer')->assertJsonPath('data.path', '/lp/eid-offer')->assertJsonPath('data.previous_slugs', ['eid-sale']);
        $this->getJson('/api/landing-pages')->assertOk()->assertJsonPath('data.0.path', '/lp/eid-offer');

        // a draft still does not exist for a visitor, under either address
        $landing->update(['status' => LandingPage::DRAFT]);
        $this->getJson('/api/landing-pages/eid-sale')->assertNotFound();
        $this->getJson('/api/landing-pages/eid-offer')->assertNotFound();
    }

    public function test_the_same_word_can_be_a_category_and_a_page_because_their_paths_differ(): void
    {
        $this->category('gifts');
        Page::create(['title' => 'Gifts', 'slug' => 'gifts', 'content' => 'x']);

        $this->assertNull(Slugs::problem('page', 'gift-ideas'));
        $this->getJson('/api/categories/gifts')->assertOk()->assertJsonPath('data.path', '/category/gifts');
        $this->getJson('/api/pages/gifts')->assertOk()->assertJsonPath('data.path', '/pages/gifts');
    }

    // ---- the homepage tiles

    private function occasions(array $tiles): HomepageSection
    {
        return HomepageSection::create(['type' => 'occasions', 'position' => 1, 'is_enabled' => true, 'tiles' => $tiles]);
    }

    private function tiles(): array
    {
        return collect($this->getJson('/api/homepage-sections')->assertOk()->json('data'))->firstWhere('type', 'occasions')['tiles'];
    }

    public function test_a_tile_linked_to_a_category_follows_the_category_when_its_address_changes(): void
    {
        $category = $this->category('eid-gifts');
        $this->occasions([['label' => 'Eid', 'image' => null, 'link_type' => 'category', 'link_category' => 'eid-gifts', 'is_enabled' => true]]);

        $this->assertSame([['label' => 'Eid', 'image' => null, 'href' => '/category/eid-gifts', 'kind' => 'category', 'slug' => 'eid-gifts', 'path' => '/category/eid-gifts']], $this->tiles());

        $category->update(['slug' => 'eid-special']);

        // the tile still stores the old slug; what the API says is today's address
        $this->assertSame([['label' => 'Eid', 'image' => null, 'href' => '/category/eid-special', 'kind' => 'category', 'slug' => 'eid-special', 'path' => '/category/eid-special']], $this->tiles());
    }

    public function test_a_tile_with_a_custom_address_shows_it_in_the_api_as_soon_as_it_is_saved(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create(['role_id' => null]));
        $section = $this->occasions([
            ['label' => 'Eid', 'image' => null, 'link_type' => 'custom', 'custom_url' => '/shop', 'is_enabled' => true],
            ['label' => 'Wedding', 'image' => null, 'link_type' => 'page', 'link_page' => 'wedding', 'is_enabled' => true],
            ['label' => 'Gift finder', 'image' => null, 'link_type' => 'gift_finder', 'is_enabled' => true],
        ]);
        Page::create(['title' => 'Wedding', 'slug' => 'wedding', 'content' => 'x']);

        $page = Livewire::test(EditHomepageSection::class, ['record' => $section->getRouteKey()]);
        $key = array_key_first($page->get('data.tiles'));
        $page->set('data.tiles.'.$key.'.custom_url', '/lp/eid-offer')->call('save')->assertHasNoFormErrors();

        $tiles = $this->tiles();
        $this->assertSame(['/lp/eid-offer', '/pages/wedding', '/gift-finder'], array_column($tiles, 'href'));
        $this->assertSame(['custom', 'page', 'gift_finder'], array_column($tiles, 'kind'));
        $this->assertSame([null, 'wedding', null], array_column($tiles, 'slug'));
        $this->assertSame(array_column($tiles, 'href'), array_column($tiles, 'path'));
        $this->assertStringNotContainsString('max-age', (string) $this->getJson('/api/homepage-sections')->headers->get('Cache-Control'), 'nothing keeps an old answer');
    }

    // ---- the sitemap and the feeds

    public function test_the_sitemap_and_the_product_feeds_use_the_same_paths_and_list_the_redirects(): void
    {
        $category = $this->category('eid-gifts');
        $category->update(['slug' => 'eid-special']);
        $product = Product::create(['category_id' => $category->id, 'name' => 'Mug', 'slug' => 'blue-mug', 'price' => 600, 'stock_quantity' => 3, 'sku' => 'M-1', 'is_active' => true]);
        Page::create(['title' => 'About', 'slug' => 'about-us', 'content' => 'x']);

        $sitemap = $this->getJson('/api/sitemap')->assertOk();

        $sitemap->assertJsonPath('products.0.path', '/product/blue-mug')->assertJsonPath('products.0.slug', 'blue-mug');
        $sitemap->assertJsonPath('pages.0.path', '/pages/about-us');
        $sitemap->assertJsonPath('categories.0.path', '/category/eid-special');
        $sitemap->assertJsonPath('redirects', [['type' => 'category', 'from' => '/category/eid-gifts', 'to' => '/category/eid-special']]);

        $site = SiteSetting::create(['site_name' => 'Anaiza Nest', 'feed_storefront_url' => 'https://shop.example.test']);
        $site->feedToken();
        $csv = $this->get('/feeds/meta.csv?token='.$site->fresh()->feed_token)->assertOk()->getContent();
        $this->assertStringContainsString('https://shop.example.test'.Slugs::path('product', $product->slug), $csv);
    }
}
