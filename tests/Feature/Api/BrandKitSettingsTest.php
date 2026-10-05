<?php

namespace Tests\Feature\Api;

use App\Filament\Pages\ManageSiteSettings;
use App\Filament\Resources\HomepageSectionResource\Pages\EditHomepageSection;
use App\Models\HomepageSection;
use App\Models\PaymentSetting;
use App\Models\SiteSetting;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class BrandKitSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_tagline_defaults_to_the_brand_kit_line(): void
    {
        SiteSetting::create(['site_name' => 'Anaiza Nest']);

        $this->assertSame('Gifted, beautifully.', $this->getJson('/api/site-settings')->json('data.tagline'));
    }

    public function test_a_blank_tagline_comes_back_null_so_the_storefront_can_hide_it(): void
    {
        SiteSetting::create(['site_name' => 'Anaiza Nest', 'tagline' => '']);

        $this->assertNull($this->getJson('/api/site-settings')->json('data.tagline'));
    }

    public function test_the_default_boilerplates_are_the_brand_kit_text_without_ranking_claims(): void
    {
        SiteSetting::create(['site_name' => 'Anaiza Nest']);

        $json = $this->getJson('/api/site-settings')->json('data');

        $this->assertSame('Anaiza Nest is a Dhaka gifting house for tea sets, gift boxes and homeware, packed by hand and ready to give.', $json['footer_about']);
        $this->assertStringStartsWith('Anaiza Nest curates ceramic and glass tea sets', $json['brand_description']);
        $this->assertStringContainsString('Fast-Signs Group, trading in Bangladesh since 2003.', $json['brand_description']);
        $this->assertStringNotContainsString('#1', $json['footer_about'].$json['brand_description']);
    }

    public function test_a_written_brand_description_wins_over_the_default(): void
    {
        SiteSetting::create(['site_name' => 'Anaiza Nest', 'brand_description' => 'Our own words.']);

        $this->assertSame('Our own words.', $this->getJson('/api/site-settings')->json('data.brand_description'));
    }

    public function test_logo_files_are_null_until_uploaded_and_resolve_to_urls_after(): void
    {
        SiteSetting::create(['site_name' => 'Anaiza Nest']);
        $json = $this->getJson('/api/site-settings')->json('data');
        $this->assertNull($json['logo_navy']);
        $this->assertNull($json['logo_ivory']);
        $this->assertNull($json['monogram']);

        SiteSetting::first()->update(['logo_navy' => 'site/navy.svg', 'monogram' => 'https://example.com/m.png']);
        $json = $this->getJson('/api/site-settings')->json('data');
        $this->assertStringEndsWith('/storage/site/navy.svg', $json['logo_navy']);
        $this->assertSame('https://example.com/m.png', $json['monogram']);
    }

    public function test_the_default_nav_names_the_page_special_prices_but_keeps_the_url(): void
    {
        $links = collect(SiteSetting::defaultNavLinks())->firstWhere('url', '/hot-deals');

        $this->assertSame('Special prices', $links['label']);
    }

    public function test_cod_enabled_follows_the_payment_setting(): void
    {
        $this->assertTrue($this->getJson('/api/site-settings')->json('data.cod_enabled'));

        PaymentSetting::create(['cod_enabled' => false]);

        $this->assertFalse($this->getJson('/api/site-settings')->json('data.cod_enabled'));
    }

    public function test_the_low_stock_threshold_defaults_to_five(): void
    {
        $this->assertSame(5, $this->getJson('/api/site-settings')->json('data.low_stock_threshold'));
    }

    public function test_built_in_homepage_sections_expose_an_editable_title_and_subtitle(): void
    {
        HomepageSection::query()->delete();
        HomepageSection::create(['type' => 'bestsellers', 'position' => 1, 'is_enabled' => true, 'custom_title' => 'Loved this season', 'custom_subtitle' => 'Chosen by hand.']);
        HomepageSection::create(['type' => 'new_arrivals', 'position' => 2, 'is_enabled' => true]);

        $rows = $this->getJson('/api/homepage-sections')->json('data');

        $this->assertSame('Loved this season', $rows[0]['custom_title']);
        $this->assertSame('Chosen by hand.', $rows[0]['custom_subtitle']);
        $this->assertNull($rows[1]['custom_title']);
        $this->assertNull($rows[1]['custom_subtitle']);
    }

    public function test_the_admin_can_set_the_section_title_and_subtitle(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create(['role_id' => null]));
        $section = HomepageSection::where('type', 'bestsellers')->firstOrFail();

        Livewire::test(EditHomepageSection::class, ['record' => $section->getRouteKey()])
            ->fillForm(['custom_title' => 'Loved this season', 'custom_subtitle' => 'Chosen by hand.'])
            ->call('save')
            ->assertHasNoFormErrors();

        $section->refresh();
        $this->assertSame('Loved this season', $section->custom_title);
        $this->assertSame('Chosen by hand.', $section->custom_subtitle);
    }

    public function test_the_admin_saves_the_tagline_low_stock_threshold_and_a_cleared_tagline(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create(['role_id' => null]));
        SiteSetting::create(['site_name' => 'Anaiza Nest']);

        Livewire::test(ManageSiteSettings::class)
            ->fillForm(['tagline' => 'Gifted, beautifully.', 'low_stock_threshold' => 3])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(3, SiteSetting::first()->low_stock_threshold);
        $this->assertSame(3, $this->getJson('/api/site-settings')->json('data.low_stock_threshold'));
    }
}
