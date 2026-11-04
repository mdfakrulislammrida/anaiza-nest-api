<?php

namespace Tests\Feature\Filament;

use App\Models\SiteSetting;
use App\Support\MediaUrl;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CourierFixtures;
use Tests\TestCase;

/**
 * Batch ADM1, B1: the admin says "Anaiza Nest" and shows the logo kept in Site settings, not "Anaiza Nest API" (APP_NAME, which mail, backups and caches also use).
 */
class AdminBrandTest extends TestCase
{
    use CourierFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        // What the server's .env says; the admin must not depend on it.
        config(['app.name' => 'Anaiza Nest API']);
    }

    private function login(): string
    {
        return $this->get('/admin/login')->assertOk()->getContent();
    }

    public function test_the_login_page_says_anaiza_nest_with_the_logo_and_never_anaiza_nest_api(): void
    {
        SiteSetting::create(['site_name' => 'Anaiza Nest', 'logo_navy' => 'site/navy.png', 'logo_ivory' => 'site/ivory.png']);

        $html = $this->login();

        $this->assertStringContainsString('<title>', $html);
        $this->assertMatchesRegularExpression('/<title>\s*Login -\s+Anaiza Nest\s*<\/title>/', $html);
        $this->assertStringContainsString('src="'.MediaUrl::resolve('site/navy.png').'"', $html);
        $this->assertStringContainsString('alt="Anaiza Nest logo"', $html);
        $this->assertStringNotContainsString('Anaiza Nest API', $html);
        $this->assertStringContainsString('Sign in', $html);
    }

    public function test_the_dark_page_uses_the_ivory_logo_when_there_is_one(): void
    {
        SiteSetting::create(['site_name' => 'Anaiza Nest', 'logo_navy' => 'site/navy.png', 'logo_ivory' => 'site/ivory.png']);

        $html = $this->login();

        $this->assertStringContainsString('src="'.MediaUrl::resolve('site/ivory.png').'"', $html);
        $this->assertStringNotContainsString('class="fi-brand-plate"', $html);
        // The two are swapped by the page's own light and dark classes.
        $this->assertStringContainsString('dark:hidden', $html);
        $this->assertStringContainsString('hidden dark:flex', $html);
    }

    public function test_without_an_ivory_logo_the_usual_one_sits_on_a_plate_and_is_not_recoloured(): void
    {
        SiteSetting::create(['site_name' => 'Anaiza Nest', 'logo_navy' => 'site/navy.png']);

        $html = $this->login();

        $this->assertStringContainsString('class="fi-brand-plate"', $html);
        $this->assertSame(2, substr_count($html, 'src="'.MediaUrl::resolve('site/navy.png').'"'), 'the same picture for light and dark, nothing else done to it');
        $this->assertStringNotContainsString('invert(', $html);
    }

    public function test_the_logo_falls_back_to_the_previous_logo_then_the_monogram_then_the_name_as_text(): void
    {
        $setting = SiteSetting::create(['site_name' => 'Anaiza Nest', 'logo_url' => 'site/old.png', 'monogram' => 'site/mono.png']);

        $this->assertStringContainsString('src="'.MediaUrl::resolve('site/old.png').'"', $this->login());

        $setting->update(['logo_url' => null]);
        $this->assertStringContainsString('src="'.MediaUrl::resolve('site/mono.png').'"', $this->login());

        $setting->update(['monogram' => null]);
        $html = $this->login();

        $this->assertStringNotContainsString('<img', explode('Sign in', $html)[0] ?? '');
        $this->assertStringContainsString('Anaiza Nest', $html);
        $this->assertStringNotContainsString('Anaiza Nest API', $html);
        $this->assertStringNotContainsString('class="fi-brand-plate"', $html);
    }

    public function test_it_works_before_any_setting_exists(): void
    {
        $this->assertSame(0, SiteSetting::count());

        $html = $this->login();

        $this->assertMatchesRegularExpression('/<title>\s*Login -\s+Anaiza Nest\s*<\/title>/', $html);
        $this->assertStringNotContainsString('Anaiza Nest API', $html);
    }

    public function test_the_browser_tab_icon_is_the_monogram_when_there_is_one(): void
    {
        $this->assertStringNotContainsString('rel="icon"', $this->login());

        SiteSetting::create(['site_name' => 'Anaiza Nest', 'monogram' => 'site/mono.png']);

        $this->assertStringContainsString('rel="icon"', $this->login());
        $this->assertStringContainsString(MediaUrl::resolve('site/mono.png'), $this->login());
    }

    public function test_the_signed_in_pages_carry_the_name_in_the_title_and_the_logo_in_the_bars(): void
    {
        SiteSetting::create(['site_name' => 'Anaiza Nest', 'logo_navy' => 'site/navy.png']);
        $this->actingAs($this->admin());

        $html = $this->get('/admin')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<title>\s*Dashboard -\s+Anaiza Nest\s*<\/title>/', $html);
        $this->assertStringContainsString('src="'.MediaUrl::resolve('site/navy.png').'"', $html);
        $this->assertStringNotContainsString('Anaiza Nest API', $html);
    }

    public function test_the_phone_styles_size_the_logo_and_keep_it_inside_the_screen(): void
    {
        $css = view('filament.admin.brand-styles')->render();

        $this->assertStringContainsString('.fi-simple-layout .fi-logo { height: 48px !important;', $css);
        $this->assertStringContainsString('max-width: 100%', $css);
        $this->assertStringContainsString('@media (max-width: 767px)', $css);
        $this->assertSame('32px', Filament::getPanel('admin')->getBrandLogoHeight());
    }

    public function test_nothing_outside_the_admin_changes_the_name(): void
    {
        $this->login();

        // APP_NAME is left alone: it still names the mail sender, the backups and the caches, and the API's own root still answers with it.
        $this->assertSame('Anaiza Nest API', config('app.name'));
        $this->assertSame('Anaiza Nest', Filament::getPanel('admin')->getBrandName());
        $this->getJson('/')->assertOk()->assertJsonPath('name', 'Anaiza Nest API');
    }
}
