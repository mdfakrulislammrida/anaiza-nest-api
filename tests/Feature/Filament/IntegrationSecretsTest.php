<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\ManageIntegrationSettings;
use App\Models\IntegrationSetting;
use App\Models\SiteSetting;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Admin > Integrations: the address to register with Google is shown (read only, with a copy button) and never typed, and the secrets
 * are write-only, like the marketing tokens.
 */
class IntegrationSecretsTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'GOCSPX-super-secret-value-9876';

    protected function setUp(): void
    {
        parent::setUp();

        SiteSetting::create(['site_name' => 'Anaiza Nest']);
        config([
            'app.url' => 'https://api.example.test',
            'services.google.client_id' => null, 'services.google.client_secret' => null,
            'services.facebook.client_id' => null, 'services.facebook.client_secret' => null,
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create(['role_id' => null]));
    }

    private function saved(): IntegrationSetting
    {
        return IntegrationSetting::create([
            'google_client_id' => '1234-abcd.apps.googleusercontent.com', 'google_client_secret' => self::SECRET,
            'google_login_enabled' => true, 'smtp_password' => 'smtp-password-value-5555',
        ]);
    }

    public function test_the_page_shows_the_computed_redirect_address_read_only_with_the_instruction_and_a_copy_button(): void
    {
        $page = Livewire::test(ManageIntegrationSettings::class);

        $page->assertSee('https://api.example.test/api/auth/google/callback')
            ->assertSee('https://api.example.test/api/auth/facebook/callback')
            ->assertSee('Add this exact URL in Google Cloud &gt; Credentials &gt; Authorised redirect URIs', false)
            ->assertSee('readonly', false)
            ->assertSee('Copy');

        // There is no field to type a redirect address into.
        $keys = array_keys((array) $page->get('data'));
        $this->assertSame([], array_values(array_filter($keys, fn (string $key): bool => str_contains($key, 'redirect'))));
    }

    public function test_the_page_warns_when_app_url_is_not_the_public_api_address(): void
    {
        config(['app.url' => 'http://localhost']);

        Livewire::test(ManageIntegrationSettings::class)->assertSee('is not the public address of the API');
    }

    public function test_a_saved_secret_is_never_on_the_page_but_the_page_says_it_exists(): void
    {
        $this->saved();

        Livewire::test(ManageIntegrationSettings::class)
            ->assertSee('Saved (ends in ••••9876)', false)
            ->assertSee('Saved (ends in ••••5555)', false)
            ->assertDontSee(self::SECRET, false)
            ->assertDontSee('smtp-password-value-5555', false)
            ->assertFormSet(['google_client_secret' => null, 'smtp_password' => null, 'google_client_id' => '1234-abcd.apps.googleusercontent.com']);
    }

    public function test_the_page_says_when_nothing_is_saved(): void
    {
        Livewire::test(ManageIntegrationSettings::class)->assertSee('Not saved yet');
    }

    public function test_saving_with_a_blank_secret_keeps_the_saved_one(): void
    {
        $this->saved();

        Livewire::test(ManageIntegrationSettings::class)->call('save');
        Livewire::test(ManageIntegrationSettings::class)->fillForm(['google_client_secret' => '', 'smtp_password' => '   ', 'smtp_host' => 'smtp.example.test'])->call('save');

        $row = IntegrationSetting::first();
        $this->assertSame(self::SECRET, $row->google_client_secret);
        $this->assertSame('smtp-password-value-5555', $row->smtp_password);
        $this->assertSame('smtp.example.test', $row->smtp_host);
        $this->assertTrue($row->google_login_enabled, 'and the switch stays on');
    }

    public function test_a_new_secret_replaces_the_old_one_and_does_not_stay_on_the_page(): void
    {
        $this->saved();

        Livewire::test(ManageIntegrationSettings::class)
            ->fillForm(['google_client_secret' => ' GOCSPX-replacement-value-1111 '])
            ->call('save')
            ->assertFormSet(['google_client_secret' => null])
            ->assertSee('Saved (ends in ••••1111)', false);

        $this->assertSame('GOCSPX-replacement-value-1111', IntegrationSetting::first()->google_client_secret);
    }

    public function test_clearing_a_secret_asks_first_and_clears_only_that_secret(): void
    {
        $this->saved();

        $page = Livewire::test(ManageIntegrationSettings::class);
        $page->mountFormComponentAction('google_client_secret', 'clearGoogleSecret');
        $this->assertSame(self::SECRET, IntegrationSetting::first()->google_client_secret, 'only asked so far');

        $page->callMountedFormComponentAction()->assertNotified('Google client secret cleared');

        $row = IntegrationSetting::first();
        $this->assertNull($row->google_client_secret);
        $this->assertSame('smtp-password-value-5555', $row->smtp_password);
        $this->assertSame('1234-abcd.apps.googleusercontent.com', $row->google_client_id);

        // With no secret the login reports itself as off at once.
        $this->assertFalse($this->getJson('/api/site-settings')->json('data.social_login.google'));
    }

    public function test_the_switch_cannot_be_saved_on_without_credentials(): void
    {
        Livewire::test(ManageIntegrationSettings::class)
            ->fillForm(['google_login_enabled' => true, 'google_client_id' => 'an-id'])
            ->call('save');

        $this->assertFalse((bool) IntegrationSetting::first()->google_login_enabled);
    }
}
