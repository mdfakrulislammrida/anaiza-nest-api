<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\ManageMarketingSettings;
use App\Models\MarketingSetting;
use App\Models\User;
use App\Support\MetaTestEvent;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Tests\TestCase;

class MarketingSettingsAdminTest extends TestCase
{
    use RefreshDatabase;

    private const META_TOKEN = 'EAAB-secret-meta-token-ABCD1234';

    private const TIKTOK_TOKEN = 'tiktok-secret-token-WXYZ5678';

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create(['role_id' => null]));
    }

    private function saved(array $overrides = []): MarketingSetting
    {
        return MarketingSetting::create($overrides + [
            'meta_pixel_id' => '123456789012345', 'meta_capi_access_token' => self::META_TOKEN,
            'tiktok_pixel_id' => 'TTPIXEL123', 'tiktok_events_api_access_token' => self::TIKTOK_TOKEN,
            'ga4_id' => 'G-ABCDE12345', 'gtm_container_id' => 'GTM-ABC123',
        ]);
    }

    public function test_the_ids_are_saved_encrypted_secrets_are_stored_encrypted_and_ids_reload_in_the_form(): void
    {
        Livewire::test(ManageMarketingSettings::class)
            ->fillForm([
                'meta_pixel_id' => ' 123456789012345 ', 'ga4_id' => 'G-ABCDE12345', 'gtm_container_id' => 'GTM-ABC123', 'tiktok_pixel_id' => 'TTPIXEL123',
                'meta_capi_access_token' => ' '.self::META_TOKEN.' ', 'tiktok_events_api_access_token' => self::TIKTOK_TOKEN,
            ])
            ->call('save');

        $row = MarketingSetting::query()->firstOrFail();
        $this->assertSame('123456789012345', $row->meta_pixel_id);
        $this->assertSame(self::META_TOKEN, $row->meta_capi_access_token);

        // At rest the secrets are ciphertext, the ids are plain text.
        $raw = DB::table('marketing_settings')->first();
        $this->assertStringNotContainsString(self::META_TOKEN, (string) $raw->meta_capi_access_token);
        $this->assertStringNotContainsString(self::TIKTOK_TOKEN, (string) $raw->tiktok_events_api_access_token);
        $this->assertSame('123456789012345', $raw->meta_pixel_id);

        // Back on the page: every id is in the form, no secret is.
        Livewire::test(ManageMarketingSettings::class)
            ->assertFormSet([
                'meta_pixel_id' => '123456789012345', 'ga4_id' => 'G-ABCDE12345', 'gtm_container_id' => 'GTM-ABC123', 'tiktok_pixel_id' => 'TTPIXEL123',
                'meta_capi_access_token' => null, 'tiktok_events_api_access_token' => null,
            ]);
    }

    public function test_a_saved_token_is_never_in_the_page_but_the_page_says_it_exists(): void
    {
        $this->saved();

        Livewire::test(ManageMarketingSettings::class)
            ->assertSee('Saved (ends in ••••1234)', false)
            ->assertSee('Saved (ends in ••••5678)', false)
            ->assertDontSee(self::META_TOKEN, false)
            ->assertDontSee(self::TIKTOK_TOKEN, false);
    }

    public function test_the_page_says_when_nothing_is_saved(): void
    {
        Livewire::test(ManageMarketingSettings::class)->assertSee('Not saved yet');
    }

    public function test_saving_with_the_secret_fields_blank_never_wipes_a_saved_secret(): void
    {
        $this->saved();

        // Save once untouched, once with a changed id, and once with the blanks as empty strings.
        Livewire::test(ManageMarketingSettings::class)->call('save');
        Livewire::test(ManageMarketingSettings::class)->fillForm(['meta_pixel_id' => '999888777666555'])->call('save');
        Livewire::test(ManageMarketingSettings::class)
            ->fillForm(['meta_capi_access_token' => '', 'tiktok_events_api_access_token' => '   '])
            ->call('save');

        $row = MarketingSetting::query()->firstOrFail();
        $this->assertSame('999888777666555', $row->meta_pixel_id);
        $this->assertSame(self::META_TOKEN, $row->meta_capi_access_token);
        $this->assertSame(self::TIKTOK_TOKEN, $row->tiktok_events_api_access_token);
    }

    public function test_a_new_token_replaces_the_old_one_and_does_not_linger_on_the_page(): void
    {
        $this->saved();

        Livewire::test(ManageMarketingSettings::class)
            ->fillForm(['meta_capi_access_token' => 'EAAB-replacement-token-9999'])
            ->call('save')
            ->assertFormSet(['meta_capi_access_token' => null])
            ->assertSee('Saved (ends in ••••9999)', false);

        $row = MarketingSetting::query()->firstOrFail();
        $this->assertSame('EAAB-replacement-token-9999', $row->meta_capi_access_token);
        $this->assertSame(self::TIKTOK_TOKEN, $row->tiktok_events_api_access_token);
    }

    public function test_clearing_a_token_needs_the_confirm_step_and_clears_only_that_token(): void
    {
        $this->saved();

        $page = Livewire::test(ManageMarketingSettings::class);

        // Opening the action only asks; nothing is cleared until it is confirmed.
        $page->mountFormComponentAction('meta_capi_access_token', 'clearMetaToken');
        $this->assertSame(self::META_TOKEN, MarketingSetting::query()->firstOrFail()->meta_capi_access_token);

        $page->callMountedFormComponentAction()->assertNotified('Meta token cleared');

        $row = MarketingSetting::query()->firstOrFail();
        $this->assertNull($row->meta_capi_access_token);
        $this->assertSame(self::TIKTOK_TOKEN, $row->tiktok_events_api_access_token);
        $this->assertSame('123456789012345', $row->meta_pixel_id);
    }

    public function test_the_test_event_button_reports_success_and_uses_the_test_code(): void
    {
        $this->saved(['meta_test_event_code' => 'TEST12345']);
        Http::fake(['graph.facebook.com/*' => Http::response(['events_received' => 1, 'fbtrace_id' => 'abc'])]);

        Livewire::test(ManageMarketingSettings::class)
            ->callAction('sendMetaTest')
            ->assertNotified('Meta test event sent');

        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/123456789012345/events')
            && $request->data()['test_event_code'] === 'TEST12345'
            && $request->data()['data'][0]['event_name'] === 'TestEvent');
    }

    public function test_the_test_event_button_shows_metas_exact_error_without_echoing_the_token(): void
    {
        $this->saved();
        Http::fake(['graph.facebook.com/*' => Http::response([
            'error' => ['message' => 'Invalid OAuth access token '.self::META_TOKEN.' - Cannot parse access token', 'type' => 'OAuthException', 'code' => 190],
        ], 400)]);

        $result = MetaTestEvent::send(MarketingSetting::query()->firstOrFail(), '203.0.113.9', 'PHPUnit');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Invalid OAuth access token', $result['message']);
        $this->assertStringContainsString('Cannot parse access token', $result['message']);
        $this->assertStringContainsString('[code 190]', $result['message']);
        $this->assertStringNotContainsString(self::META_TOKEN, $result['message']);

        Livewire::test(ManageMarketingSettings::class)
            ->callAction('sendMetaTest')
            ->assertNotified('Meta test event failed');
    }

    public function test_the_test_event_button_asks_for_the_settings_first_and_survives_a_dead_connection(): void
    {
        Http::fake();
        $empty = MetaTestEvent::send(new MarketingSetting, null, null);
        $this->assertFalse($empty['ok']);
        $this->assertStringContainsString('Save the Meta Pixel ID', $empty['message']);
        Http::assertNothingSent();

        $this->saved();
        Http::fake(fn () => throw new ConnectionException('cURL error 28 for token '.self::META_TOKEN));
        $down = MetaTestEvent::send(MarketingSetting::query()->firstOrFail(), null, null);
        $this->assertFalse($down['ok']);
        $this->assertStringContainsString('Could not reach Meta', $down['message']);
        $this->assertStringNotContainsString(self::META_TOKEN, $down['message']);
    }

    public function test_the_model_never_serialises_its_tokens(): void
    {
        $row = $this->saved();

        $this->assertArrayNotHasKey('meta_capi_access_token', $row->toArray());
        $this->assertArrayNotHasKey('tiktok_events_api_access_token', $row->toArray());
        $this->assertStringNotContainsString(self::META_TOKEN, $row->toJson());
        // Reading it directly (the conversion jobs do) still works.
        $this->assertSame(self::META_TOKEN, $row->meta_capi_access_token);
    }

    public function test_the_public_settings_api_returns_the_pixel_ids_and_no_token_key(): void
    {
        $this->saved(['meta_test_event_code' => 'TEST12345']);

        $response = $this->getJson('/api/marketing-settings')->assertOk()
            ->assertJsonPath('data.meta_pixel_id', '123456789012345')
            ->assertJsonPath('data.tiktok_pixel_id', 'TTPIXEL123')
            ->assertJsonPath('data.ga4_id', 'G-ABCDE12345')
            ->assertJsonPath('data.gtm_container_id', 'GTM-ABC123');

        $keys = array_keys($response->json('data'));
        $this->assertSame([], array_filter($keys, fn (string $key) => str_contains($key, 'token') || str_contains($key, 'secret') || str_contains($key, 'test_event')));
        $this->assertStringNotContainsString(self::META_TOKEN, $response->getContent());
        $this->assertStringNotContainsString(self::TIKTOK_TOKEN, $response->getContent());
    }

    public function test_no_public_get_route_returns_a_token(): void
    {
        $this->saved();

        $checked = 0;
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/') || ! in_array('GET', $route->methods(), true) || str_contains($route->uri(), '{')) {
                continue;
            }

            $body = $this->getJson('/'.$route->uri())->getContent();
            $this->assertStringNotContainsString(self::META_TOKEN, $body, $route->uri());
            $this->assertStringNotContainsString(self::TIKTOK_TOKEN, $body, $route->uri());
            $this->assertStringNotContainsString('meta_capi_access_token', $body, $route->uri());
            $this->assertStringNotContainsString('tiktok_events_api_access_token', $body, $route->uri());
            $checked++;
        }

        $this->assertGreaterThan(5, $checked);
    }
}
