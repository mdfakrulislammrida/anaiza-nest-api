<?php

namespace Tests\Feature\Api;

use App\Models\Customer;
use App\Models\IntegrationSetting;
use App\Models\SiteSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Laravel\Socialite\Contracts\Factory as SocialiteFactory;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

/**
 * The checks around "Continue with Google": a state that only this browser holds, and a one-time code instead of a token in
 * the address bar. Also customer sign-in lifetime and "sign out everywhere".
 */
class SocialLoginSecurityTest extends TestCase
{
    use RefreshDatabase;

    private const NONCE = 'nonce-held-by-this-browser';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Mail::fake();
        SiteSetting::create(['site_name' => 'Anaiza Nest']);
        config(['app.frontend_url' => 'https://shop.test']);

        IntegrationSetting::query()->delete();
        IntegrationSetting::create([
            'google_login_enabled' => true, 'google_client_id' => 'id', 'google_client_secret' => 'secret',
        ]);
    }

    private function state(string $nonce = self::NONCE): string
    {
        return hash_hmac('sha256', 'oauth-state:'.$nonce, config('app.key'));
    }

    private function fakeProviderUser(string $email = 'gina@example.com'): void
    {
        $user = new SocialiteUser;
        $user->map(['id' => '1', 'name' => 'Gina Das', 'email' => $email]);

        $provider = Mockery::mock();
        $provider->shouldReceive('stateless')->andReturnSelf();
        $provider->shouldReceive('user')->andReturn($user);

        $factory = Mockery::mock(SocialiteFactory::class);
        $factory->shouldReceive('driver')->with('google')->andReturn($provider);
        $this->app->instance(SocialiteFactory::class, $factory);
    }

    private function returnFromProvider(?string $state, ?string $cookieNonce)
    {
        $request = $cookieNonce === null ? $this : $this->withUnencryptedCookie('anaiza_oauth_state', $cookieNonce);

        return $request->get('/api/auth/google/callback'.($state === null ? '' : '?state='.$state));
    }

    public function test_beginning_sign_in_sets_a_state_cookie_and_sends_its_signed_form_to_the_provider(): void
    {
        $response = $this->get('/api/auth/google/redirect');

        $response->assertRedirect();
        $cookie = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === 'anaiza_oauth_state');
        $this->assertNotNull($cookie, 'the browser is given a nonce');
        $this->assertTrue($cookie->isHttpOnly());

        parse_str((string) parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertSame($this->state($cookie->getValue()), $query['state'] ?? null);
    }

    public function test_a_matching_state_creates_the_account_and_hands_back_a_code_not_a_token(): void
    {
        $this->fakeProviderUser();

        $response = $this->returnFromProvider($this->state(), self::NONCE);

        $location = $response->headers->get('Location');
        $this->assertStringStartsWith('https://shop.test/auth/callback?code=', $location);
        $this->assertStringNotContainsString('token=', $location);
        $this->assertSame(1, Customer::where('email', 'gina@example.com')->count());
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_the_code_is_exchanged_for_a_token_exactly_once(): void
    {
        $this->fakeProviderUser();
        $location = $this->returnFromProvider($this->state(), self::NONCE)->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        $this->postJson('/api/auth/social/exchange', ['code' => $query['code']])
            ->assertOk()
            ->assertJsonStructure(['token', 'customer' => ['id', 'email']])
            ->assertJsonPath('customer.email', 'gina@example.com');

        $this->postJson('/api/auth/social/exchange', ['code' => $query['code']])
            ->assertStatus(422)
            ->assertJsonPath('message', 'That sign-in link has ended. Please try again.');
    }

    public function test_the_code_stops_working_after_five_minutes(): void
    {
        $this->fakeProviderUser();
        $location = $this->returnFromProvider($this->state(), self::NONCE)->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        $this->travel(6)->minutes();

        $this->postJson('/api/auth/social/exchange', ['code' => $query['code']])->assertStatus(422);
    }

    public function test_a_made_up_or_empty_code_gets_nothing(): void
    {
        $this->postJson('/api/auth/social/exchange', ['code' => 'made-up'])->assertStatus(422);
        $this->postJson('/api/auth/social/exchange', [])->assertStatus(422);
    }

    public function test_a_callback_from_a_browser_that_did_not_ask_is_refused(): void
    {
        $this->fakeProviderUser();

        // No cookie: someone else's link opened in this browser.
        $this->returnFromProvider($this->state(), null)->assertRedirect('https://shop.test/auth/callback?error=social_login_failed');

        // A cookie, but a state made for another browser.
        $this->returnFromProvider($this->state('another-browsers-nonce'), self::NONCE)->assertRedirect('https://shop.test/auth/callback?error=social_login_failed');

        // A cookie, no state at all.
        $this->returnFromProvider(null, self::NONCE)->assertRedirect('https://shop.test/auth/callback?error=social_login_failed');

        $this->assertSame(0, Customer::count());
    }

    public function test_a_customer_sign_in_token_expires_after_thirty_days(): void
    {
        $this->assertSame(60 * 24 * 30, config('sanctum.expiration'));

        $customer = Customer::create(['name' => 'Priya', 'email' => 'priya@example.com', 'password' => 'a-good-password-1']);
        $token = $customer->createToken('storefront')->plainTextToken;

        $this->withToken($token)->getJson('/api/auth/me')->assertOk();

        $this->travel(31)->days();

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_sign_out_everywhere_ends_every_token(): void
    {
        $customer = Customer::create(['name' => 'Priya', 'email' => 'priya@example.com', 'password' => 'a-good-password-1']);
        $phone = $customer->createToken('storefront')->plainTextToken;
        $laptop = $customer->createToken('storefront')->plainTextToken;

        $this->withToken($phone)->postJson('/api/auth/logout-all')->assertOk();

        $this->assertSame(0, $customer->tokens()->count());

        $this->app['auth']->forgetGuards();
        $this->withToken($laptop)->getJson('/api/auth/me')->assertUnauthorized();
    }
}
