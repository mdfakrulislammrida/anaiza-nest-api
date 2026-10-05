<?php

namespace Tests\Feature\Api;

use App\Filament\Pages\ManageSiteSettings;
use App\Models\SiteSetting;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class HomepageWordingTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_homepage_wording_field_is_null_until_the_admin_writes_one(): void
    {
        SiteSetting::create(['site_name' => 'Anaiza Nest']);

        $homepage = $this->getJson('/api/site-settings')->assertOk()->json('data.homepage');

        $this->assertSame(
            ['hero_badge', 'hero_title', 'hero_text', 'hot_deals_tile_text', 'new_arrivals_tile_text', 'newsletter_headline', 'newsletter_text'],
            array_keys($homepage),
        );
        $this->assertSame([], array_filter($homepage, fn ($value) => $value !== null));
    }

    public function test_written_wording_is_returned(): void
    {
        SiteSetting::create([
            'site_name' => 'Anaiza Nest',
            'hero_badge' => 'New season',
            'hero_hot_deals_text' => 'Eid offers',
            'newsletter_headline' => 'Join us',
        ]);

        $homepage = $this->getJson('/api/site-settings')->json('data.homepage');

        $this->assertSame('New season', $homepage['hero_badge']);
        $this->assertSame('Eid offers', $homepage['hot_deals_tile_text']);
        $this->assertSame('Join us', $homepage['newsletter_headline']);
        $this->assertNull($homepage['hero_title']);
    }

    public function test_the_default_about_blurb_makes_no_ranking_claim(): void
    {
        $this->assertStringNotContainsString('#1', SiteSetting::defaultFooterAbout());
        $this->assertStringNotContainsString('#1', $this->getJson('/api/site-settings')->json('data.footer_about'));
    }

    public function test_the_admin_saves_and_clears_the_wording(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create(['role_id' => null]));
        SiteSetting::create(['site_name' => 'Anaiza Nest']);

        Livewire::test(ManageSiteSettings::class)
            ->fillForm(['hero_hot_deals_text' => 'Eid offers', 'newsletter_headline' => 'Join us'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Eid offers', SiteSetting::first()->hero_hot_deals_text);

        Livewire::test(ManageSiteSettings::class)
            ->fillForm(['hero_hot_deals_text' => ''])
            ->call('save');

        $this->assertNull($this->getJson('/api/site-settings')->json('data.homepage.hot_deals_tile_text'));
    }
}
