<?php

namespace Tests\Feature\Api;

use App\Models\IntegrationSetting;
use App\Models\MarketingSetting;
use App\Models\Page;
use App\Models\PaymentSetting;
use App\Support\System\GoLiveCheck;
use App\Support\System\SystemEvents;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Support\AdminRecords;
use Tests\TestCase;

class GoLiveCheckTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** @return array<string, array{status: string, label: string, detail: string}> */
    private function rows(): array
    {
        $rows = [];

        foreach (GoLiveCheck::run() as $row) {
            $rows[$row['label']] = $row;
        }

        return $rows;
    }

    private function verdict(string $label): string
    {
        return $this->rows()[$label]['status'] ?? 'MISSING';
    }

    public function test_environment_debug_and_addresses_are_judged(): void
    {
        config(['app.env' => 'local', 'app.debug' => true, 'app.url' => 'http://localhost:8787', 'app.frontend_url' => 'http://localhost:3000']);
        $this->assertSame(['FAIL', 'FAIL', 'FAIL', 'FAIL'], [$this->verdict('APP_ENV'), $this->verdict('APP_DEBUG'), $this->verdict('APP_URL'), $this->verdict('FRONTEND_URL')]);

        config(['app.env' => 'production', 'app.debug' => false, 'app.url' => 'https://api.anaizanest.com/', 'app.frontend_url' => 'https://anaizanest.com']);
        $this->assertSame(['PASS', 'PASS', 'PASS', 'PASS'], [$this->verdict('APP_ENV'), $this->verdict('APP_DEBUG'), $this->verdict('APP_URL'), $this->verdict('FRONTEND_URL')]);

        config(['app.url' => 'https://other.example.com', 'app.frontend_url' => 'https://new.anaizanest.com']);
        $this->assertSame(['WARN', 'WARN'], [$this->verdict('APP_URL'), $this->verdict('FRONTEND_URL')]);
    }

    public function test_cors_must_list_the_shop_and_the_origins_are_printed(): void
    {
        config(['cors.allowed_origins' => ['https://new.anaizanest.com']]);
        $this->assertSame('FAIL', $this->verdict('CORS allowed origins'));

        config(['cors.allowed_origins' => ['https://anaizanest.com', 'https://www.anaizanest.com']]);
        $row = $this->rows()['CORS allowed origins'];
        $this->assertSame('PASS', $row['status']);
        $this->assertSame('https://anaizanest.com, https://www.anaizanest.com', $row['detail']);

        config(['cors.allowed_origins' => ['https://anaizanest.com', 'http://localhost:3000']]);
        $this->assertSame('WARN', $this->verdict('CORS allowed origins'));
    }

    public function test_mail_is_judged_from_its_settings_and_nothing_is_sent(): void
    {
        Mail::fake();
        Http::fake();

        config(['mail.default' => 'log']);
        $this->assertSame('FAIL', $this->verdict('Mail'));

        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example-mail.test', 'mail.mailers.smtp.port' => 587, 'mail.mailers.smtp.username' => 'shop', 'mail.mailers.smtp.password' => 'a-very-secret-smtp-password', 'mail.from.address' => 'hello@anaizanest.com']);
        $row = $this->rows()['Mail'];
        $this->assertSame('PASS', $row['status']);
        $this->assertStringNotContainsString('a-very-secret-smtp-password', $row['detail']);
        $this->assertStringContainsString('password set', $row['detail']);

        config(['mail.mailers.smtp.password' => '']);
        $this->assertSame('FAIL', $this->verdict('Mail'));

        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        Http::assertNothingSent();
    }

    public function test_the_scheduler_must_have_run_in_the_last_five_minutes(): void
    {
        $this->assertSame('FAIL', $this->verdict('Scheduler heartbeat'));

        Carbon::setTestNow('2026-10-26 10:00:00');
        SystemEvents::mark('scheduler', 'ran');
        Carbon::setTestNow('2026-10-26 10:04:00');
        $this->assertSame('PASS', $this->verdict('Scheduler heartbeat'));

        Carbon::setTestNow('2026-10-26 10:07:00');
        $this->assertSame('FAIL', $this->verdict('Scheduler heartbeat'));
    }

    public function test_wallet_methods_need_numbers_and_the_shop_needs_some_way_to_pay(): void
    {
        PaymentSetting::create(['cod_enabled' => false]);
        $this->assertSame('FAIL', $this->verdict('Payment methods'));

        PaymentSetting::query()->update(['cod_enabled' => true, 'bkash_number' => '+8801712345678', 'nagad_number' => '12345']);
        $rows = $this->rows();

        $this->assertSame('PASS', $rows['Payment methods']['status']);
        $this->assertSame('PASS', $rows['bKash']['status']);
        $this->assertSame('WARN', $rows['Nagad']['status'], 'a number that is not a mobile number is flagged');
        $this->assertStringNotContainsString('01712345678', $rows['bKash']['detail']);
        $this->assertStringContainsString('+8...678', $rows['bKash']['detail']);
    }

    public function test_an_active_product_with_a_photo_is_needed_and_the_rest_are_counted(): void
    {
        $this->assertSame('FAIL', $this->verdict('Products with a photo'));

        $with = AdminRecords::product();
        $with->images()->create(['url' => 'products/a.png', 'sort_order' => 0]);
        AdminRecords::product();
        AdminRecords::product(['is_active' => false]);

        $rows = $this->rows();
        $this->assertSame(['PASS', '1 active'], [$rows['Products with a photo']['status'], $rows['Products with a photo']['detail']]);
        $this->assertSame('1 active product(s) have none and show a plain card', $rows['Products without a photo']['detail']);
    }

    public function test_the_five_pages_are_looked_for_under_their_usual_names(): void
    {
        $this->assertSame(['FAIL', 'FAIL', 'FAIL', 'FAIL', 'FAIL'], array_map(fn (string $l) => $this->verdict($l), ['Page: About', 'Page: Delivery / shipping', 'Page: Returns', 'Page: Terms', 'Page: Privacy']));

        foreach (['about-us', 'shipping', 'returns', 'terms-and-conditions', 'privacy-policy'] as $slug) {
            Page::create(['title' => $slug, 'slug' => $slug, 'content' => '<p>x</p>']);
        }

        $this->assertSame(['PASS', 'PASS', 'PASS', 'PASS', 'PASS'], array_map(fn (string $l) => $this->verdict($l), ['Page: About', 'Page: Delivery / shipping', 'Page: Returns', 'Page: Terms', 'Page: Privacy']));
    }

    public function test_marketing_ids_are_masked_and_tokens_only_say_set(): void
    {
        MarketingSetting::create(['meta_pixel_id' => '123456789012345', 'ga4_id' => 'G-ABCDEF1234', 'gtm_container_id' => 'GTM-ABC1234', 'meta_capi_access_token' => 'EAAB-super-secret-capi-token', 'meta_test_event_code' => 'TEST12345']);
        $rows = $this->rows();

        $this->assertSame('PASS', $rows['Meta pixel']['status']);
        $this->assertSame('12...345', $rows['Meta pixel']['detail']);
        $this->assertSame('WARN', $rows['TikTok pixel']['status']);
        $this->assertSame(['PASS', 'set'], [$rows['Meta conversions token']['status'], $rows['Meta conversions token']['detail']]);
        $this->assertSame('WARN', $rows['Meta test event code']['status'], 'a test code left in would send sales as tests');
    }

    public function test_social_login_state_is_reported_without_secrets(): void
    {
        config(['app.url' => 'https://api.anaizanest.com', 'services.google.client_id' => null, 'services.google.client_secret' => null]);
        $this->assertSame('WARN', $this->verdict('Google login'));

        IntegrationSetting::create(['google_client_id' => 'client-id-123.apps.googleusercontent.com', 'google_client_secret' => 'GOCSPX-very-secret', 'google_login_enabled' => true]);
        $row = $this->rows()['Google login'];

        $this->assertSame('PASS', $row['status']);
        $this->assertStringContainsString('https://api.anaizanest.com/api/auth/google/callback', $row['detail']);
        $this->assertStringNotContainsString('GOCSPX', $row['detail']);
        $this->assertStringNotContainsString('client-id-123', $row['detail']);

        IntegrationSetting::query()->update(['google_login_enabled' => false]);
        $this->assertSame('WARN', $this->verdict('Google login'));
    }

    public function test_the_command_prints_a_summary_exits_non_zero_on_a_fail_and_never_prints_a_secret(): void
    {
        config(['app.env' => 'local', 'mail.default' => 'smtp', 'mail.mailers.smtp.password' => 'a-very-secret-smtp-password', 'mail.mailers.smtp.host' => 'smtp.example-mail.test', 'mail.mailers.smtp.username' => 'shop']);
        MarketingSetting::create(['meta_pixel_id' => '123456789012345', 'meta_capi_access_token' => 'EAAB-super-secret-capi-token']);
        IntegrationSetting::create(['smtp_password' => 'another-secret-smtp', 'google_client_secret' => 'GOCSPX-very-secret']);

        $exit = \Illuminate\Support\Facades\Artisan::call('golive:check');
        $output = \Illuminate\Support\Facades\Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertMatchesRegularExpression('/\d+ PASS, \d+ WARN, \d+ FAIL/', $output);
        $this->assertStringContainsString('FAIL  APP_ENV', $output);

        foreach (['a-very-secret-smtp-password', 'EAAB-super-secret-capi-token', '123456789012345', 'GOCSPX', 'another-secret-smtp'] as $secret) {
            $this->assertStringNotContainsString($secret, $output);
        }
    }
}
