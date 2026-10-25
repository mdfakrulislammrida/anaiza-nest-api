<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\ManageIntegrationSettings;
use App\Models\Customer;
use App\Models\IntegrationSetting;
use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class SocialLoginSwitchesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // No credentials in .env for these tests: only what the admin saves counts.
        config([
            'services.google.client_id' => null, 'services.google.client_secret' => null,
            'services.facebook.client_id' => null, 'services.facebook.client_secret' => null,
            'services.google.redirect' => 'http://localhost/api/auth/google/callback',
            'services.facebook.redirect' => 'http://localhost/api/auth/facebook/callback',
        ]);

        SiteSetting::create(['site_name' => 'Anaiza Nest']);
    }

    private function admin(): void
    {
        $this->actingAs(User::factory()->create(['role_id' => null]));
    }

    private function social(): array
    {
        return $this->getJson('/api/site-settings')->assertOk()->json('data.social_login');
    }

    // ---- the public settings

    public function test_both_providers_are_off_by_default(): void
    {
        $this->assertSame(['google' => false, 'facebook' => false], $this->social());

        IntegrationSetting::create([]);

        $this->assertSame(['google' => false, 'facebook' => false], $this->social());
    }

    public function test_each_provider_switches_on_and_off_on_its_own(): void
    {
        IntegrationSetting::create([
            'google_client_id' => 'g-id', 'google_client_secret' => 'g-secret', 'google_login_enabled' => true,
            'facebook_app_id' => 'f-id', 'facebook_app_secret' => 'f-secret', 'facebook_login_enabled' => false,
        ]);

        $this->assertSame(['google' => true, 'facebook' => false], $this->social());

        IntegrationSetting::first()->update(['google_login_enabled' => false, 'facebook_login_enabled' => true]);

        $this->assertSame(['google' => false, 'facebook' => true], $this->social());
    }

    public function test_a_switch_that_is_on_without_credentials_reports_off(): void
    {
        IntegrationSetting::create(['google_login_enabled' => true, 'facebook_app_id' => 'f-id', 'facebook_login_enabled' => true]);

        $this->assertSame(['google' => false, 'facebook' => false], $this->social(), 'no secret, no id');
    }

    public function test_credentials_from_the_servers_env_count(): void
    {
        config(['services.google.client_id' => 'env-id', 'services.google.client_secret' => 'env-secret']);
        IntegrationSetting::create(['google_login_enabled' => true]);

        $this->assertTrue($this->social()['google']);
    }

    public function test_the_credentials_never_appear_in_the_public_settings(): void
    {
        IntegrationSetting::create(['google_client_id' => 'super-secret-id', 'google_client_secret' => 'super-secret-value', 'google_login_enabled' => true]);

        $body = $this->getJson('/api/site-settings')->getContent();

        $this->assertStringNotContainsString('super-secret', $body);
        $this->assertStringNotContainsString('client_secret', $body);
    }

    // ---- the admin page

    public function test_the_admin_can_switch_a_provider_on_once_its_credentials_are_there(): void
    {
        $this->admin();

        Livewire::test(ManageIntegrationSettings::class)
            ->fillForm(['google_client_id' => 'g-id', 'google_client_secret' => 'g-secret', 'google_login_enabled' => true])
            ->call('save');

        $this->assertTrue(IntegrationSetting::first()->google_login_enabled);
        $this->assertFalse(IntegrationSetting::first()->facebook_login_enabled);
        $this->assertSame(['google' => true, 'facebook' => false], $this->social());
    }

    public function test_the_empty_credential_guard_keeps_it_off_and_says_why(): void
    {
        $this->admin();

        $page = Livewire::test(ManageIntegrationSettings::class)
            ->fillForm(['google_login_enabled' => true, 'facebook_login_enabled' => true, 'facebook_app_id' => 'only-an-id'])
            ->call('save');

        $settings = IntegrationSetting::first();
        $this->assertFalse($settings->google_login_enabled);
        $this->assertFalse($settings->facebook_login_enabled, 'an ID without a secret is not enough');
        $page->assertNotified();
        $page->assertSet('data.google_login_enabled', false);
    }

    public function test_the_toggle_springs_back_off_when_flipped_without_credentials(): void
    {
        $this->admin();

        Livewire::test(ManageIntegrationSettings::class)
            ->set('data.google_login_enabled', true)
            ->assertSet('data.google_login_enabled', false)
            ->assertNotified();
    }

    public function test_a_saved_secret_counts_even_though_the_box_is_blank(): void
    {
        IntegrationSetting::create(['google_client_id' => 'g-id', 'google_client_secret' => 'g-secret']);
        $this->admin();

        Livewire::test(ManageIntegrationSettings::class)
            ->set('data.google_login_enabled', true)
            ->assertSet('data.google_login_enabled', true);
    }

    public function test_switching_a_provider_off_keeps_its_credentials(): void
    {
        IntegrationSetting::create(['google_client_id' => 'g-id', 'google_client_secret' => 'g-secret', 'google_login_enabled' => true]);
        $this->admin();

        Livewire::test(ManageIntegrationSettings::class)
            ->fillForm(['google_login_enabled' => false])
            ->call('save');

        $settings = IntegrationSetting::first();
        $this->assertFalse($settings->google_login_enabled);
        $this->assertSame('g-id', $settings->google_client_id);
        $this->assertSame('g-secret', $settings->google_client_secret, 'the secret is not wiped by leaving its box blank');
    }

    // ---- the endpoints

    public function test_an_off_provider_redirects_a_browser_to_a_friendly_error_and_never_reaches_the_provider(): void
    {
        IntegrationSetting::create(['google_client_id' => 'g-id', 'google_client_secret' => 'g-secret', 'google_login_enabled' => false]);

        $redirect = $this->get('/api/auth/google/redirect');
        $redirect->assertRedirect();
        $this->assertStringContainsString('/auth/callback?error=social_login_unavailable&provider=google', $redirect->headers->get('Location'));
        $this->assertStringNotContainsString('accounts.google.com', $redirect->headers->get('Location'));

        $callback = $this->get('/api/auth/facebook/callback?code=abc');
        $callback->assertRedirect();
        $this->assertStringContainsString('error=social_login_unavailable&provider=facebook', $callback->headers->get('Location'));
        $this->assertSame(0, Customer::count(), 'nothing was created');
    }

    public function test_an_off_provider_answers_json_requests_with_a_403(): void
    {
        // No client ID or secret anywhere: it is not set up yet, and says so.
        $this->getJson('/api/auth/google/redirect')->assertForbidden()->assertJsonPath('message', 'Google login is not set up yet. Please sign in with your email and password.');
        $this->getJson('/api/auth/facebook/callback')->assertForbidden();

        // Set up but switched off: not available just now.
        IntegrationSetting::create(['google_client_id' => 'g-id', 'google_client_secret' => 'g-secret', 'google_login_enabled' => false]);
        $this->getJson('/api/auth/google/redirect')->assertForbidden()->assertJsonPath('message', 'Signing in with Google is not available just now. Please sign in with your email and password.');
    }

    public function test_an_on_provider_still_goes_to_the_provider(): void
    {
        IntegrationSetting::create(['google_client_id' => 'g-id', 'google_client_secret' => 'g-secret', 'google_login_enabled' => true]);

        $response = $this->get('/api/auth/google/redirect');

        $response->assertRedirect();
        $this->assertStringContainsString('accounts.google.com', $response->headers->get('Location'));

        // The other one is still off.
        $this->assertStringContainsString('social_login_unavailable', $this->get('/api/auth/facebook/redirect')->headers->get('Location'));
    }

    // ---- nothing is taken from existing customers

    public function test_a_customer_who_signed_up_with_a_provider_keeps_their_account_and_can_still_log_in_with_a_password(): void
    {
        $customer = Customer::create(['name' => 'Priya Rahman', 'email' => 'priya@example.com', 'phone' => '01711111111', 'password' => Hash::make('a-real-password')]);
        IntegrationSetting::create(['google_client_id' => 'g-id', 'google_client_secret' => 'g-secret', 'google_login_enabled' => true]);
        $token = $customer->createToken('storefront')->plainTextToken;

        // The admin switches Google off.
        IntegrationSetting::first()->update(['google_login_enabled' => false]);

        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'email' => 'priya@example.com']);
        $this->assertSame(1, $customer->tokens()->count(), 'no token is revoked');

        $this->postJson('/api/auth/login', ['email' => 'priya@example.com', 'password' => 'a-real-password'])->assertOk();
        $this->withToken($token)->getJson('/api/auth/me')->assertOk();
    }
}
