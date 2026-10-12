<?php

namespace Tests\Feature\Api;

use App\Http\Controllers\Api\PasswordResetController;
use App\Mail\TransactionalMail;
use App\Models\Customer;
use App\Models\SiteSetting;
use App\Support\BrandVoice;
use App\Support\Email\TemplateDefinitions;
use App\Support\PasswordReset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use ReflectionProperty;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-14 06:00:00');
        Cache::flush();
        Mail::fake();
        SiteSetting::create(['site_name' => 'Anaiza Nest']);

        $this->customer = Customer::create(['name' => 'Priya Rahman', 'email' => 'priya@example.com', 'phone' => '01711111111', 'password' => 'the-old-password']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** Every request in a test re-runs what earlier ones queued for after the response; a real request is a fresh process. */
    private function forgetTerminating(): void
    {
        (new ReflectionProperty($this->app, 'terminatingCallbacks'))->setValue($this->app, []);
    }

    private function ask(string $login)
    {
        $this->forgetTerminating();

        return $this->postJson('/api/auth/forgot-password', ['email' => $login]);
    }

    private function sentLink(): ?string
    {
        $mail = Mail::sent(TransactionalMail::class)->last();

        return $mail && preg_match('#/reset-password\?token=([A-Za-z0-9_\-.]+)#', $mail->render(), $m) ? $m[1] : null;
    }

    // ---- asking for a link

    public function test_an_account_gets_an_email_with_a_link_and_the_answer_is_the_same_for_everyone(): void
    {
        $known = $this->ask('priya@example.com')->assertOk();
        $unknown = $this->ask('nobody@example.com')->assertOk();

        $this->assertSame($known->json('message'), $unknown->json('message'), 'no way to tell which addresses have an account');
        Mail::assertSent(TransactionalMail::class, 1);
        Mail::assertSent(TransactionalMail::class, fn (TransactionalMail $m) => $m->templateKey === TemplateDefinitions::PASSWORD_RESET && $m->hasTo('priya@example.com'));
        $this->assertNotNull($this->sentLink());
    }

    public function test_the_email_is_in_the_shops_voice_and_the_link_is_the_one_button(): void
    {
        $this->ask('priya@example.com');

        Mail::assertSent(TransactionalMail::class, function (TransactionalMail $m) {
            $html = $m->render();

            return str_contains($html, 'Hello Priya Rahman')
                && str_contains($html, 'one hour')
                && str_contains($html, 'Choose a new password')
                && str_contains($html, 'If you did not ask for this, you can leave this email alone')
                && ! str_contains($html, '/unsubscribe/');
        });
    }

    public function test_the_email_is_sent_even_when_the_shop_has_no_site_settings_row_yet(): void
    {
        SiteSetting::query()->delete();

        $this->ask('priya@example.com')->assertOk();

        Mail::assertSent(TransactionalMail::class, 1);
    }

    public function test_an_email_in_capitals_finds_the_same_account(): void
    {
        $this->ask('  Priya@Example.COM ')->assertOk();

        Mail::assertSent(TransactionalMail::class, 1);
    }

    public function test_a_phone_number_finds_the_account_and_the_email_goes_to_the_accounts_own_address(): void
    {
        $this->ask('+880 1711-111111')->assertOk();

        Mail::assertSent(TransactionalMail::class, fn (TransactionalMail $m) => $m->hasTo('priya@example.com'));
    }

    public function test_an_account_with_no_email_gets_nothing_and_looks_the_same(): void
    {
        Customer::create(['name' => 'Guest', 'phone' => '01722222222', 'password' => 'x-guest-pass']);

        $this->ask('01722222222')->assertOk()->assertJsonPath('message', PasswordResetController::SENT);

        Mail::assertNothingSent();
    }

    public function test_one_address_is_emailed_at_most_three_times_an_hour_with_the_same_answer(): void
    {
        foreach (range(1, 5) as $_) {
            $this->ask('priya@example.com')->assertOk();
        }

        Mail::assertSent(TransactionalMail::class, 3);
    }

    public function test_a_request_needs_something_to_look_for(): void
    {
        $this->postJson('/api/auth/forgot-password', [])->assertStatus(422);
    }

    // ---- the link

    public function test_a_fresh_link_is_valid_and_a_forged_or_cut_one_is_not(): void
    {
        $token = PasswordReset::token($this->customer);

        $this->getJson('/api/auth/reset-password/check?token='.$token)->assertOk()->assertJson(['valid' => true]);
        $this->getJson('/api/auth/reset-password/check?token='.$token.'x')->assertOk()->assertJson(['valid' => false]);
        $this->getJson('/api/auth/reset-password/check?token=garbage')->assertOk()->assertJson(['valid' => false]);
        $this->getJson('/api/auth/reset-password/check')->assertOk()->assertJson(['valid' => false]);
    }

    public function test_a_link_ends_after_an_hour(): void
    {
        $token = PasswordReset::token($this->customer);

        Carbon::setTestNow(now()->addMinutes(59));
        $this->assertNotNull(PasswordReset::resolve($token));

        Carbon::setTestNow(now()->addMinutes(2));
        $this->assertNull(PasswordReset::resolve($token));
    }

    public function test_a_link_for_one_customer_cannot_be_used_for_another(): void
    {
        $other = Customer::create(['name' => 'Other', 'email' => 'other@example.com', 'phone' => '01733333333', 'password' => 'other-password']);
        $token = PasswordReset::token($this->customer);
        [$payload, $signature] = explode('.', $token);
        $swapped = rtrim(strtr(base64_encode($other->id.'.'.now()->addHour()->getTimestamp()), '+/', '-_'), '=').'.'.$signature;

        $this->assertNull(PasswordReset::resolve($swapped));
    }

    // ---- choosing the password

    public function test_the_link_sets_the_new_password_once_and_signs_everyone_out(): void
    {
        $session = $this->customer->createToken('storefront')->plainTextToken;
        $this->ask('priya@example.com');
        $token = $this->sentLink();

        $this->postJson('/api/auth/reset-password', ['token' => $token, 'password' => 'a-brand-new-one', 'password_confirmation' => 'a-brand-new-one'])
            ->assertOk()->assertJsonPath('message', 'Your password is changed. You can log in with it now.');

        $this->assertTrue(Hash::check('a-brand-new-one', $this->customer->fresh()->password));
        $this->assertSame(0, $this->customer->tokens()->count(), 'every session is ended');
        $this->postJson('/api/auth/login', ['email' => 'priya@example.com', 'password' => 'the-old-password'])->assertStatus(422);
        $this->postJson('/api/auth/login', ['email' => 'priya@example.com', 'password' => 'a-brand-new-one'])->assertOk();

        // The same link cannot be used again.
        $this->postJson('/api/auth/reset-password', ['token' => $token, 'password' => 'another-new-one', 'password_confirmation' => 'another-new-one'])
            ->assertStatus(422)->assertJsonValidationErrors('token');
        $this->assertFalse(Hash::check('another-new-one', $this->customer->fresh()->password));
        $this->assertNotSame('', $session);
    }

    public function test_an_older_link_stops_working_once_the_password_has_changed(): void
    {
        $first = PasswordReset::token($this->customer);

        $this->customer->update(['password' => 'changed-another-way']);

        $this->postJson('/api/auth/reset-password', ['token' => $first, 'password' => 'a-brand-new-one', 'password_confirmation' => 'a-brand-new-one'])
            ->assertStatus(422)->assertJsonValidationErrors('token');
    }

    public function test_the_new_password_must_be_long_enough_and_confirmed(): void
    {
        $token = PasswordReset::token($this->customer);

        $this->postJson('/api/auth/reset-password', ['token' => $token, 'password' => 'short', 'password_confirmation' => 'short'])
            ->assertStatus(422)->assertJsonValidationErrors('password');
        $this->postJson('/api/auth/reset-password', ['token' => $token, 'password' => 'a-brand-new-one', 'password_confirmation' => 'different-one'])
            ->assertStatus(422)->assertJsonValidationErrors('password');

        $this->assertTrue(Hash::check('the-old-password', $this->customer->fresh()->password), 'a refused change leaves the password alone');
    }

    public function test_someone_who_signed_up_with_google_can_set_a_password(): void
    {
        $social = Customer::create(['name' => 'Social', 'email' => 'social@example.com', 'password' => Str::random(32)]);
        $token = PasswordReset::token($social);

        $this->postJson('/api/auth/reset-password', ['token' => $token, 'password' => 'my-own-password', 'password_confirmation' => 'my-own-password'])->assertOk();
        $this->postJson('/api/auth/login', ['email' => 'social@example.com', 'password' => 'my-own-password'])->assertOk();
    }

    public function test_the_wording_is_editable_and_follows_the_brand_voice(): void
    {
        $defaults = TemplateDefinitions::defaults(TemplateDefinitions::PASSWORD_RESET);

        $this->assertSame([], BrandVoice::notes($defaults['subject'].' '.$defaults['intro_html'].' '.$defaults['closing_html']));
        $this->assertArrayHasKey(TemplateDefinitions::PASSWORD_RESET, TemplateDefinitions::all());
    }
}
