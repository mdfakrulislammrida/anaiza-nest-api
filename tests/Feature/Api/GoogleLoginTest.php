<?php

namespace Tests\Feature\Api;

use App\Models\Customer;
use App\Models\IntegrationSetting;
use App\Models\SiteSetting;
use App\Providers\IntegrationSettingsServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Laravel\Socialite\Contracts\Factory as SocialiteFactory;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

/**
 * "Continue with Google", from the button to the sign-in token: the address Google is sent back to is worked out in code (so it can never
 * be the "Missing required parameter: redirect_uri" of a blank setting), a Google login that is not set up says so plainly, and a
 * secret never leaves the server.
 */
class GoogleLoginTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'https://api.example.test';

    private const CALLBACK = 'https://api.example.test/api/auth/google/callback';

    private const NONCE = 'nonce-held-by-this-browser';

    private const SECRET = 'GOCSPX-super-secret-value-9876';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Mail::fake();
        SiteSetting::create(['site_name' => 'Anaiza Nest']);

        // The state of the server this is run on does not matter: nothing from .env, an API address and a storefront address.
        config([
            'app.url' => self::API, 'app.frontend_url' => 'https://shop.test',
            'services.google.client_id' => null, 'services.google.client_secret' => null, 'services.google.redirect' => null,
            'services.facebook.client_id' => null, 'services.facebook.client_secret' => null, 'services.facebook.redirect' => null,
        ]);
    }

    private function on(): IntegrationSetting
    {
        $row = IntegrationSetting::create(['google_login_enabled' => true, 'google_client_id' => '1234-abcd.apps.googleusercontent.com', 'google_client_secret' => self::SECRET]);

        // What the start of a real request does: the saved credentials are put into the services config before the controller runs.
        (new IntegrationSettingsServiceProvider($this->app))->boot();

        return $row;
    }

    private function state(string $nonce = self::NONCE): string
    {
        return hash_hmac('sha256', 'oauth-state:'.$nonce, config('app.key'));
    }

    /** @return array<string, string> the query of the address a response sends the browser to */
    private function queryOf($response): array
    {
        parse_str((string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY), $query);

        return $query;
    }

    /** @param  array<string, mixed>  $raw  what the provider said about the person */
    private function fakeGoogleUser(string $email = 'gina@example.com', array $raw = ['email_verified' => true], string $expectedRedirect = self::CALLBACK): void
    {
        $user = new SocialiteUser;
        $user->map(['id' => '1', 'name' => 'Gina Das', 'email' => $email]);
        $user->user = $raw;

        $provider = Mockery::mock();
        $provider->shouldReceive('stateless')->andReturnSelf();
        // The trip back to Google to trade the code is told the same address as the trip out.
        $provider->shouldReceive('redirectUrl')->with($expectedRedirect)->atLeast()->once()->andReturnSelf();
        $provider->shouldReceive('user')->andReturn($user);

        $factory = Mockery::mock(SocialiteFactory::class);
        $factory->shouldReceive('driver')->with('google')->andReturn($provider);
        $this->app->instance(SocialiteFactory::class, $factory);
    }

    private function returnFromGoogle()
    {
        return $this->withUnencryptedCookie('anaiza_oauth_state', self::NONCE)->get('/api/auth/google/callback?state='.$this->state());
    }

    // ---- not set up

    public function test_with_nothing_set_up_google_login_says_so_and_never_reaches_google(): void
    {
        $browser = $this->get('/api/auth/google/redirect');

        $browser->assertRedirect('https://shop.test/auth/callback?error=social_login_unavailable&provider=google&reason=not_set_up');
        $this->assertStringNotContainsString('google.com', (string) $browser->headers->get('Location'));

        $this->getJson('/api/auth/google/redirect')->assertForbidden()
            ->assertJsonPath('message', 'Google login is not set up yet. Please sign in with your email and password.');
        $this->getJson('/api/auth/google/callback')->assertForbidden();

        $this->assertFalse($this->getJson('/api/site-settings')->json('data.social_login.google'), 'the button is hidden');
    }

    public function test_a_blank_client_id_or_a_blank_secret_is_not_set_up_even_with_the_switch_on(): void
    {
        $row = IntegrationSetting::create(['google_login_enabled' => true, 'google_client_id' => 'only-an-id']);

        $this->getJson('/api/auth/google/redirect')->assertForbidden()->assertJsonPath('message', 'Google login is not set up yet. Please sign in with your email and password.');
        $this->assertFalse($this->getJson('/api/site-settings')->json('data.social_login.google'));

        $row->update(['google_client_id' => '', 'google_client_secret' => self::SECRET]);

        $this->getJson('/api/auth/google/redirect')->assertForbidden()->assertJsonPath('message', 'Google login is not set up yet. Please sign in with your email and password.');
    }

    public function test_set_up_but_switched_off_is_a_different_message_and_a_hidden_button(): void
    {
        $this->on()->update(['google_login_enabled' => false]);

        $this->getJson('/api/auth/google/redirect')->assertForbidden()
            ->assertJsonPath('message', 'Signing in with Google is not available just now. Please sign in with your email and password.');
        $this->get('/api/auth/google/redirect')->assertRedirect('https://shop.test/auth/callback?error=social_login_unavailable&provider=google');
        $this->assertFalse($this->getJson('/api/site-settings')->json('data.social_login.google'));
    }

    public function test_a_blank_admin_field_never_blanks_what_the_server_has(): void
    {
        config(['services.google.client_id' => 'env-client-id', 'services.google.client_secret' => 'env-client-secret']);
        IntegrationSetting::create(['google_client_id' => '', 'google_client_secret' => '', 'google_login_enabled' => true]);

        (new IntegrationSettingsServiceProvider($this->app))->boot();

        $this->assertSame('env-client-id', config('services.google.client_id'));
        $this->assertSame('env-client-secret', config('services.google.client_secret'));
        $this->assertTrue($this->getJson('/api/site-settings')->json('data.social_login.google'), 'the server credentials count');
    }

    // ---- the address Google sends people back to

    public function test_the_callback_address_is_the_api_address_plus_the_named_route(): void
    {
        $this->assertSame(self::CALLBACK, IntegrationSetting::callbackUrl('google'));
        $this->assertSame('https://api.example.test/api/auth/facebook/callback', IntegrationSetting::callbackUrl('facebook'));
        $this->assertSame(self::CALLBACK, rtrim(self::API, '/').route('social.google.callback', [], false));

        config(['app.url' => self::API.'/']);
        $this->assertSame(self::CALLBACK, IntegrationSetting::callbackUrl('google'), 'a trailing slash in APP_URL makes no difference');
    }

    public function test_google_is_sent_the_computed_address_whatever_is_in_the_services_config(): void
    {
        $this->on();

        foreach ([null, '', 'http://localhost/wrong', 'https://old.example.test/api/auth/google/callback'] as $typed) {
            config(['services.google.redirect' => $typed]);

            $response = $this->get('/api/auth/google/redirect');
            $query = $this->queryOf($response);

            $this->assertSame('accounts.google.com', parse_url((string) $response->headers->get('Location'), PHP_URL_HOST));
            $this->assertSame(self::CALLBACK, $query['redirect_uri'] ?? null, 'typed: '.var_export($typed, true));
            $this->assertSame('1234-abcd.apps.googleusercontent.com', $query['client_id']);
            $this->assertSame('code', $query['response_type']);
            $this->assertNotSame('', $query['state'] ?? '');
        }
    }

    public function test_the_address_does_not_follow_the_host_the_request_came_in_on(): void
    {
        $this->on();

        $query = $this->queryOf($this->get('http://some-other-name.test:8080/api/auth/google/redirect'));

        $this->assertSame(self::CALLBACK, $query['redirect_uri']);
    }

    public function test_the_secret_is_not_in_the_address_google_is_sent_to(): void
    {
        $this->on();

        $this->assertStringNotContainsString(self::SECRET, (string) $this->get('/api/auth/google/redirect')->headers->get('Location'));
    }

    // ---- coming back: the account and the token

    public function test_coming_back_makes_an_account_for_a_new_email_and_the_code_is_traded_for_a_token(): void
    {
        $this->on();
        $this->fakeGoogleUser();

        $location = (string) $this->returnFromGoogle()->headers->get('Location');

        $this->assertStringStartsWith('https://shop.test/auth/callback?code=', $location);
        $customer = Customer::where('email', 'gina@example.com')->first();
        $this->assertNotNull($customer);
        $this->assertSame('Gina Das', $customer->name);

        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->postJson('/api/auth/social/exchange', ['code' => $query['code']])
            ->assertOk()
            ->assertJsonPath('customer.email', 'gina@example.com')
            ->assertJsonStructure(['token']);

        $this->assertDatabaseHas('personal_access_tokens', ['tokenable_id' => $customer->id, 'name' => 'storefront']);
    }

    public function test_coming_back_links_to_the_account_that_already_has_the_email_without_touching_it(): void
    {
        $this->on();
        $existing = Customer::create(['name' => 'Gina (shop account)', 'email' => 'gina@example.com', 'phone' => '01712345678', 'password' => Hash::make('her-own-password')]);
        $this->fakeGoogleUser();

        $location = (string) $this->returnFromGoogle()->headers->get('Location');

        $this->assertSame(1, Customer::where('email', 'gina@example.com')->count(), 'no second account');
        $existing->refresh();
        $this->assertSame('Gina (shop account)', $existing->name);
        $this->assertTrue(Hash::check('her-own-password', $existing->password));

        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->postJson('/api/auth/social/exchange', ['code' => $query['code']])->assertOk()->assertJsonPath('customer.id', $existing->id);
    }

    public function test_an_email_google_says_is_not_verified_makes_and_links_nothing(): void
    {
        $this->on();
        Customer::create(['name' => 'Gina', 'email' => 'gina@example.com', 'phone' => '01712345678', 'password' => Hash::make('x-password')]);
        $this->fakeGoogleUser('gina@example.com', ['email_verified' => false]);

        $this->returnFromGoogle()->assertRedirect('https://shop.test/auth/callback?error=email_not_verified');

        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertSame(1, Customer::count());
    }

    // ---- secrets

    public function test_the_secret_is_never_in_a_public_response(): void
    {
        $this->on();
        IntegrationSetting::first()->update(['smtp_password' => 'smtp-password-value-5555', 'facebook_app_secret' => 'facebook-app-secret-value-4444']);

        $secrets = [self::SECRET, 'smtp-password-value-5555', 'facebook-app-secret-value-4444'];
        $checked = 0;

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/') || ! in_array('GET', $route->methods(), true) || str_contains($route->uri(), '{') || str_contains($route->uri(), 'auth/')) {
                continue;
            }

            $response = $this->getJson('/'.$route->uri());
            $body = $response->getContent().json_encode($response->headers->all());

            foreach ($secrets as $secret) {
                $this->assertStringNotContainsString($secret, $body, $route->uri());
            }
            foreach (['google_client_secret', 'facebook_app_secret', 'smtp_password', 'client_secret'] as $key) {
                $this->assertStringNotContainsString($key, $body, $route->uri());
            }
            $checked++;
        }

        $this->assertGreaterThan(5, $checked);

        // What the public settings say about social login is two switches, nothing else.
        $this->assertSame(['google' => true, 'facebook' => false], $this->getJson('/api/site-settings')->json('data.social_login'));
    }

    public function test_the_model_never_serialises_its_secrets(): void
    {
        $row = $this->on();
        $row->update(['smtp_password' => 'smtp-password-value-5555', 'facebook_app_secret' => 'facebook-app-secret-value-4444']);

        foreach (['google_client_secret', 'smtp_password', 'facebook_app_secret'] as $field) {
            $this->assertArrayNotHasKey($field, $row->fresh()->toArray());
        }
        $this->assertStringNotContainsString(self::SECRET, $row->fresh()->toJson());
        // The server's own code still reads it.
        $this->assertSame(self::SECRET, $row->fresh()->google_client_secret);
        $this->assertSame('ends in ••••9876', $row->fresh()->secretHint('google_client_secret'));
    }
}
