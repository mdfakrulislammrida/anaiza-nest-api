<?php

namespace Tests\Feature\Api;

use App\Jobs\SendMetaConversionEvent;
use App\Jobs\SendTikTokConversionEvent;
use App\Models\Category;
use App\Models\CookieConsentSetting;
use App\Models\Order;
use App\Models\Page;
use App\Models\Product;
use App\Models\SiteSetting;
use App\Support\MenuLinks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class MenuAndCookieConsentTest extends TestCase
{
    use RefreshDatabase;

    private function settings(array $attributes): SiteSetting
    {
        $settings = SiteSetting::query()->firstOrCreate([]);
        $settings->update($attributes);

        return $settings;
    }

    private function nav(): array
    {
        return $this->getJson('/api/site-settings')->assertOk()->json('data.nav_links');
    }

    public function test_page_and_category_items_follow_the_current_slug(): void
    {
        $page = Page::create(['title' => 'About us', 'slug' => 'about', 'content' => '<p>x</p>']);
        $category = Category::create(['name' => 'Tea Sets', 'slug' => 'tea-sets']);
        $this->settings(['nav_links' => [
            ['type' => 'page', 'page_id' => $page->id, 'label' => ''],
            ['type' => 'category', 'category_id' => $category->id, 'label' => ''],
            ['type' => 'blog', 'label' => ''],
            ['type' => 'custom', 'label' => 'Offers', 'url' => '/hot-deals'],
        ]]);

        $this->assertSame([
            ['label' => 'About us', 'url' => '/pages/about', 'children' => []],
            ['label' => 'Tea Sets', 'url' => '/category/tea-sets', 'children' => []],
            ['label' => 'Blog', 'url' => '/blog', 'children' => []],
            ['label' => 'Offers', 'url' => '/hot-deals', 'children' => []],
        ], $this->nav());

        // Renaming a slug (and the title) never breaks the menu: the link and the default label follow.
        $page->update(['slug' => 'our-story', 'title' => 'Our story']);
        $category->update(['slug' => 'tea-gifts', 'name' => 'Tea gifts']);

        $nav = $this->nav();
        $this->assertSame(['Our story', '/pages/our-story'], [$nav[0]['label'], $nav[0]['url']]);
        $this->assertSame(['Tea gifts', '/category/tea-gifts'], [$nav[1]['label'], $nav[1]['url']]);
    }

    public function test_a_label_the_admin_wrote_beats_the_name(): void
    {
        $page = Page::create(['title' => 'About us', 'slug' => 'about', 'content' => '<p>x</p>']);
        $this->settings(['nav_links' => [['type' => 'page', 'page_id' => $page->id, 'label' => 'Who we are']]]);

        $this->assertSame('Who we are', $this->nav()[0]['label']);
    }

    public function test_an_item_whose_target_was_deleted_drops_out(): void
    {
        $page = Page::create(['title' => 'About us', 'slug' => 'about', 'content' => '<p>x</p>']);
        $this->settings(['nav_links' => [
            ['type' => 'page', 'page_id' => $page->id],
            ['type' => 'custom', 'label' => 'Contact', 'url' => '/contact'],
        ]]);
        $page->delete();

        $this->assertSame(['Contact'], array_column($this->nav(), 'label'));
    }

    public function test_items_saved_before_types_existed_still_work_as_custom_urls(): void
    {
        $this->settings(['nav_links' => [['label' => 'Shop', 'url' => '/shop'], ['label' => 'Contact', 'url' => '/contact']]]);

        $this->assertSame([['label' => 'Shop', 'url' => '/shop', 'children' => []], ['label' => 'Contact', 'url' => '/contact', 'children' => []]], $this->nav());
    }

    public function test_unsafe_or_incomplete_custom_items_are_dropped(): void
    {
        $this->settings(['nav_links' => [
            ['type' => 'custom', 'label' => 'Bad', 'url' => 'javascript:alert(1)'],
            ['type' => 'custom', 'label' => 'Worse', 'url' => '//evil.example'],
            ['type' => 'custom', 'label' => '', 'url' => '/x'],
            ['type' => 'custom', 'label' => 'Fine', 'url' => 'https://example.com/a'],
        ]]);

        $this->assertSame(['Fine'], array_column($this->nav(), 'label'));
    }

    public function test_one_level_of_sub_items_is_served_and_deeper_levels_are_ignored(): void
    {
        $category = Category::create(['name' => 'Tea Sets', 'slug' => 'tea-sets']);
        $this->settings(['nav_links' => [[
            'type' => 'custom', 'label' => 'Shop', 'url' => '/shop',
            'children' => [
                ['type' => 'category', 'category_id' => $category->id, 'label' => ''],
                ['type' => 'custom', 'label' => 'All', 'url' => '/shop', 'children' => [['type' => 'custom', 'label' => 'Too deep', 'url' => '/x']]],
            ],
        ]]]);

        $shop = $this->nav()[0];
        $this->assertSame([['label' => 'Tea Sets', 'url' => '/category/tea-sets'], ['label' => 'All', 'url' => '/shop']], $shop['children']);
    }

    public function test_the_auto_list_toggle_puts_menu_categories_under_shop_without_repeating_any(): void
    {
        $a = Category::create(['name' => 'Alpha', 'slug' => 'alpha']);
        Category::create(['name' => 'Beta', 'slug' => 'beta']);
        Category::create(['name' => 'Hidden', 'slug' => 'hidden', 'show_in_menu' => false]);
        $this->settings(['nav_auto_categories' => false, 'nav_links' => [
            ['type' => 'custom', 'label' => 'Shop', 'url' => '/shop', 'children' => [['type' => 'category', 'category_id' => $a->id]]],
            ['type' => 'custom', 'label' => 'Contact', 'url' => '/contact'],
        ]]);

        $this->assertCount(1, $this->nav()[0]['children']);

        SiteSetting::query()->update(['nav_auto_categories' => true]);
        $nav = $this->nav();

        $this->assertSame(['Alpha', 'Beta'], array_column($nav[0]['children'], 'label'));
        $this->assertSame([], $nav[1]['children']);
    }

    public function test_auto_list_does_nothing_without_a_shop_item(): void
    {
        Category::create(['name' => 'Alpha', 'slug' => 'alpha']);
        $this->settings(['nav_auto_categories' => true, 'nav_links' => [['type' => 'custom', 'label' => 'Contact', 'url' => '/contact']]]);

        $this->assertSame([], $this->nav()[0]['children']);
    }

    public function test_footer_columns_are_capped_at_three_and_resolved(): void
    {
        $page = Page::create(['title' => 'About us', 'slug' => 'about', 'content' => '<p>x</p>']);
        $column = fn (string $title) => ['title' => $title, 'items' => [['type' => 'page', 'page_id' => $page->id]]];
        $this->settings(['footer_columns' => [$column('One'), $column('Two'), $column('Three'), $column('Four')]]);

        $json = $this->getJson('/api/site-settings')->json('data');

        $this->assertSame(['One', 'Two', 'Three'], array_column($json['footer_columns'], 'title'));
        $this->assertSame([['label' => 'About us', 'url' => '/pages/about']], $json['footer_columns'][0]['items']);
        // The flat list older storefront builds read.
        $this->assertCount(3, $json['footer_links']);
    }

    public function test_the_default_footer_is_one_customer_care_column(): void
    {
        $json = $this->getJson('/api/site-settings')->json('data');

        $this->assertSame(['Customer care'], array_column($json['footer_columns'], 'title'));
        $this->assertCount(5, $json['footer_columns'][0]['items']);
    }

    public function test_menu_resolution_is_a_pure_function_of_the_items(): void
    {
        $this->assertSame([], MenuLinks::resolve(null));
        $this->assertSame([], MenuLinks::resolveColumns([['title' => '', 'items' => [['label' => 'x', 'url' => '/x']]]]));
    }

    // ---- cookie consent

    public function test_cookie_settings_default_to_a_calm_notice_in_the_shops_voice(): void
    {
        $data = $this->getJson('/api/cookie-consent')->assertOk()->json('data');

        $this->assertTrue($data['enabled']);
        $this->assertSame('notice', $data['mode']);
        $this->assertSame('Accept all', $data['labels']['accept']);
        $this->assertSame('Reject non-essential', $data['labels']['reject']);
        $this->assertSame('Customize', $data['labels']['customize']);
        $this->assertNull($data['privacy_url']);
        $this->assertStringNotContainsStringIgnoringCase('gdpr', $data['banner_text']);
        $this->assertStringNotContainsString('!', $data['banner_text']);
    }

    public function test_cookie_wording_and_the_privacy_page_are_editable_and_the_link_follows_the_slug(): void
    {
        $page = Page::create(['title' => 'Privacy', 'slug' => 'privacy', 'content' => '<p>x</p>']);
        CookieConsentSetting::create(['mode' => 'opt_in', 'banner_text' => 'Cookies help us.', 'privacy_page_id' => $page->id, 'accept_label' => 'Allow all']);

        $data = $this->getJson('/api/cookie-consent')->json('data');
        $this->assertSame(['opt_in', 'Cookies help us.', '/pages/privacy', 'Allow all'], [$data['mode'], $data['banner_text'], $data['privacy_url'], $data['labels']['accept']]);

        $page->update(['slug' => 'privacy-policy']);
        $this->assertSame('/pages/privacy-policy', $this->getJson('/api/cookie-consent')->json('data.privacy_url'));

        $page->delete();
        $this->assertNull($this->getJson('/api/cookie-consent')->json('data.privacy_url'));
    }

    private function order(array $overrides = []): array
    {
        $category = Category::firstOrCreate(['slug' => 'tea-sets'], ['name' => 'Tea Sets']);
        $product = Product::first() ?? Product::create([
            'category_id' => $category->id, 'name' => 'Mug', 'slug' => 'mug', 'price' => 600,
            'stock_quantity' => 50, 'sku' => 'M-1', 'is_active' => true,
        ]);

        return array_merge([
            'customer_name' => 'Fakrul Islam', 'customer_phone' => '01710000000', 'customer_address' => 'House 1',
            'division' => 'Dhaka', 'district' => 'Dhaka', 'thana' => 'Banani', 'payment_method' => 'cod',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ], $overrides);
    }

    public function test_in_opt_in_mode_the_conversion_jobs_wait_for_marketing_consent(): void
    {
        Bus::fake([SendMetaConversionEvent::class, SendTikTokConversionEvent::class]);
        CookieConsentSetting::create(['mode' => 'opt_in']);

        $this->postJson('/api/orders', $this->order(['marketing_consent' => false]))->assertCreated();
        $this->postJson('/api/orders', $this->order(['customer_phone' => '01710000001']))->assertCreated();
        Bus::assertNotDispatched(SendMetaConversionEvent::class);
        Bus::assertNotDispatched(SendTikTokConversionEvent::class);

        $this->postJson('/api/orders', $this->order(['customer_phone' => '01710000002', 'marketing_consent' => true]))->assertCreated();
        Bus::assertDispatchedTimes(SendMetaConversionEvent::class, 1);
        Bus::assertDispatchedTimes(SendTikTokConversionEvent::class, 1);

        $this->assertSame([false, false, true], Order::orderBy('id')->pluck('marketing_consent')->all());
    }

    public function test_in_notice_mode_the_conversion_jobs_run_as_they_always_have(): void
    {
        Bus::fake([SendMetaConversionEvent::class, SendTikTokConversionEvent::class]);
        CookieConsentSetting::create(['mode' => 'notice']);

        $this->postJson('/api/orders', $this->order(['marketing_consent' => false]))->assertCreated();

        Bus::assertDispatchedTimes(SendMetaConversionEvent::class, 1);
        Bus::assertDispatchedTimes(SendTikTokConversionEvent::class, 1);
    }

    public function test_with_no_settings_at_all_or_the_banner_off_nothing_waits_for_consent(): void
    {
        Bus::fake([SendMetaConversionEvent::class, SendTikTokConversionEvent::class]);

        $this->postJson('/api/orders', $this->order())->assertCreated();
        Bus::assertDispatchedTimes(SendMetaConversionEvent::class, 1);

        CookieConsentSetting::create(['mode' => 'opt_in', 'enabled' => false]);
        $this->postJson('/api/orders', $this->order(['customer_phone' => '01710000009']))->assertCreated();
        Bus::assertDispatchedTimes(SendMetaConversionEvent::class, 2);
    }

    public function test_a_page_body_never_carries_a_second_h1(): void
    {
        Page::create(['title' => 'About us', 'slug' => 'about', 'content' => '<h1>Our story</h1><table><tr><td>x</td></tr></table>']);

        $content = $this->getJson('/api/pages/about')->assertOk()->json('data.content');

        $this->assertStringNotContainsString('<h1', $content);
        $this->assertStringContainsString('<h2>Our story</h2>', $content);
        $this->assertStringContainsString('<table>', $content);
    }
}
