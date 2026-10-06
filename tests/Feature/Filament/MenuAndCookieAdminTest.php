<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\ManageCookieConsent;
use App\Filament\Pages\ManageSiteSettings;
use App\Filament\Resources\PageResource\Pages\EditPage;
use App\Models\Category;
use App\Models\CookieConsentSetting;
use App\Models\Page;
use App\Models\SiteSetting;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MenuAndCookieAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create(['role_id' => null]));
    }

    public function test_the_header_menu_takes_pages_categories_the_blog_and_nested_items(): void
    {
        $page = Page::create(['title' => 'About us', 'slug' => 'about', 'content' => '<p>x</p>']);
        $category = Category::create(['name' => 'Tea Sets', 'slug' => 'tea-sets']);
        SiteSetting::create(['site_name' => 'Anaiza Nest']);

        Livewire::test(ManageSiteSettings::class)
            ->fillForm([
                'nav_auto_categories' => false,
                'nav_links' => [
                    ['type' => 'custom', 'label' => 'Shop', 'url' => '/shop', 'children' => [
                        ['type' => 'category', 'category_id' => $category->id, 'label' => ''],
                    ]],
                    ['type' => 'page', 'page_id' => $page->id, 'label' => ''],
                    ['type' => 'blog', 'label' => ''],
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $nav = $this->getJson('/api/site-settings')->json('data.nav_links');
        $this->assertSame(['Shop', 'About us', 'Blog'], array_column($nav, 'label'));
        $this->assertSame([['label' => 'Tea Sets', 'url' => '/category/tea-sets']], $nav[0]['children']);
    }

    public function test_a_page_item_needs_a_page_and_a_custom_item_needs_a_label_and_url(): void
    {
        SiteSetting::create(['site_name' => 'Anaiza Nest']);

        Livewire::test(ManageSiteSettings::class)
            ->fillForm(['nav_links' => [['type' => 'page', 'page_id' => null, 'label' => '']]])
            ->call('save')
            ->assertHasFormErrors(['nav_links.0.page_id' => 'required']);

        Livewire::test(ManageSiteSettings::class)
            ->fillForm(['nav_links' => [['type' => 'custom', 'label' => '', 'url' => '']]])
            ->call('save')
            ->assertHasFormErrors(['nav_links.0.label' => 'required', 'nav_links.0.url' => 'required']);
    }

    public function test_the_footer_allows_three_columns_and_no_more(): void
    {
        SiteSetting::create(['site_name' => 'Anaiza Nest']);
        $column = fn (string $title) => ['title' => $title, 'items' => [['type' => 'custom', 'label' => 'Contact', 'url' => '/contact']]];

        Livewire::test(ManageSiteSettings::class)
            ->fillForm(['footer_columns' => [$column('One'), $column('Two'), $column('Three')]])
            ->call('save')
            ->assertHasNoFormErrors();

        Livewire::test(ManageSiteSettings::class)
            ->fillForm(['footer_columns' => [$column('One'), $column('Two'), $column('Three'), $column('Four')]])
            ->call('save')
            ->assertHasFormErrors(['footer_columns']);

        $this->assertCount(3, SiteSetting::first()->footer_columns);
    }

    public function test_the_settings_form_opens_with_todays_menus_filled_in(): void
    {
        SiteSetting::create(['site_name' => 'Anaiza Nest']);

        $page = Livewire::test(ManageSiteSettings::class);

        $this->assertSame('Home', $page->get('data.nav_links')[array_key_first($page->get('data.nav_links'))]['label']);
        $this->assertSame('Customer care', $page->get('data.footer_columns')[array_key_first($page->get('data.footer_columns'))]['title']);
    }

    public function test_the_cookie_consent_page_saves_and_keeps_default_wording_blank(): void
    {
        $page = Page::create(['title' => 'Privacy', 'slug' => 'privacy', 'content' => '<p>x</p>']);

        $test = Livewire::test(ManageCookieConsent::class);
        $this->assertSame(CookieConsentSetting::defaultBannerText(), $test->get('data.banner_text'));

        $test->fillForm([
            'mode' => 'opt_in',
            'privacy_page_id' => $page->id,
            'reject_label' => 'Only the essentials',
        ])->call('save')->assertHasNoFormErrors();

        $settings = CookieConsentSetting::first();
        $this->assertSame('opt_in', $settings->mode);
        $this->assertSame($page->id, $settings->privacy_page_id);
        $this->assertSame('Only the essentials', $settings->reject_label);
        $this->assertNull($settings->banner_text, 'Untouched default wording stays blank.');
        $this->assertNull($settings->accept_label);
    }

    public function test_the_cms_page_form_has_the_raw_html_box_guidance_and_counters(): void
    {
        $page = Page::create(['title' => 'About us', 'slug' => 'about', 'content' => '<h2>Hello</h2>']);

        $this->get("/admin/pages/{$page->id}/edit")
            ->assertOk()
            ->assertSee('Paste or upload raw HTML instead')
            ->assertSee('comparison table', false)
            ->assertSee('/70 characters (recommended 50-60)')
            ->assertSee('/160 characters (recommended 120-155)');

        Livewire::test(EditPage::class, ['record' => $page->getRouteKey()])
            ->fillForm(['meta_title' => str_repeat('a', 71)])
            ->call('save')
            ->assertHasFormErrors(['meta_title']);

        Livewire::test(EditPage::class, ['record' => $page->getRouteKey()])
            ->fillForm(['content' => '<table><tr><td>x</td></tr></table>'])
            ->call('save')
            ->assertHasNoFormErrors();
    }
}
