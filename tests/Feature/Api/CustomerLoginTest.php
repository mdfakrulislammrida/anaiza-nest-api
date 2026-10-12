<?php

namespace Tests\Feature\Api;

use App\Mail\TransactionalMail;
use App\Models\Customer;
use App\Models\SiteSetting;
use App\Support\CustomerLookup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class CustomerLoginTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Mail::fake();
        SiteSetting::create(['site_name' => 'Anaiza Nest']);
        $this->customer = Customer::create(['name' => 'Priya Rahman', 'email' => 'priya@example.com', 'phone' => '01711234567', 'password' => 'the-right-password']);
    }

    private function login(string $who, string $password = 'the-right-password')
    {
        return $this->postJson('/api/auth/login', ['email' => $who, 'password' => $password]);
    }

    public function test_an_email_still_logs_in_in_any_case(): void
    {
        $this->login('priya@example.com')->assertOk()->assertJsonPath('customer.id', $this->customer->id);
        $this->login('  Priya@Example.COM ')->assertOk();
    }

    public function test_a_phone_number_logs_in_however_it_is_typed(): void
    {
        foreach (['01711234567', '+8801711234567', '8801711234567', '+880 1711-234567', '017 1123 4567', '01711-234567'] as $typed) {
            $this->login($typed)->assertOk()->assertJsonPath('customer.id', $this->customer->id);
        }
    }

    public function test_a_phone_stored_with_a_country_code_or_spaces_is_found_by_the_local_form(): void
    {
        $plus = Customer::create(['name' => 'Plus', 'email' => 'plus@example.com', 'phone' => '+8801811111111', 'password' => 'plus-password-1']);
        $spaced = Customer::create(['name' => 'Spaced', 'email' => 'spaced@example.com', 'phone' => '+880 1911-222222', 'password' => 'spaced-password-1']);

        $this->login('01811111111', 'plus-password-1')->assertOk()->assertJsonPath('customer.id', $plus->id);
        $this->login('01911222222', 'spaced-password-1')->assertOk()->assertJsonPath('customer.id', $spaced->id);
    }

    public function test_a_wrong_password_unknown_phone_and_unknown_email_all_get_the_same_answer(): void
    {
        $wrong = $this->login('01711234567', 'nope-nope-nope')->assertStatus(422);
        $unknownPhone = $this->login('01799999999')->assertStatus(422);
        $unknownEmail = $this->login('nobody@example.com')->assertStatus(422);
        $notEither = $this->login('hello')->assertStatus(422);

        foreach ([$wrong, $unknownPhone, $unknownEmail, $notEither] as $response) {
            $response->assertJsonPath('errors.email.0', 'The provided credentials are incorrect.');
        }
    }

    public function test_two_customers_with_one_phone_number_are_told_apart_by_the_password(): void
    {
        // A guest order creates a customer by phone, so a number can belong to more than one.
        $guest = Customer::create(['name' => 'Guest', 'phone' => '01711234567', 'password' => 'a-random-guest-password']);

        $this->login('01711234567', 'the-right-password')->assertOk()->assertJsonPath('customer.id', $this->customer->id);
        $this->login('01711234567', 'a-random-guest-password')->assertOk()->assertJsonPath('customer.id', $guest->id);
    }

    public function test_the_login_asks_for_something_in_the_field(): void
    {
        $this->postJson('/api/auth/login', ['password' => 'x'])->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'Please enter your email address or phone number.');
    }

    public function test_the_lookup_finds_by_either_and_nothing_for_junk(): void
    {
        $this->assertSame([$this->customer->id], CustomerLookup::byLogin('priya@example.com')->pluck('id')->all());
        $this->assertSame([$this->customer->id], CustomerLookup::byLogin('+880 1711 234567')->pluck('id')->all());
        $this->assertSame([], CustomerLookup::byLogin('')->pluck('id')->all());
        $this->assertSame([], CustomerLookup::byLogin('12345')->pluck('id')->all());
    }

    public function test_the_password_reset_finds_an_account_by_phone_the_same_way(): void
    {
        $this->postJson('/api/auth/forgot-password', ['email' => '+880 1711-234567'])->assertOk();

        Mail::assertSent(TransactionalMail::class, fn (TransactionalMail $mail) => $mail->hasTo('priya@example.com'));
    }

    public function test_too_many_attempts_for_one_account_are_slowed_down(): void
    {
        foreach (range(1, 5) as $_) {
            $this->login('01711234567', 'wrong-password-1')->assertStatus(422);
        }

        $this->login('01711234567', 'wrong-password-1')->assertStatus(429);
    }
}
