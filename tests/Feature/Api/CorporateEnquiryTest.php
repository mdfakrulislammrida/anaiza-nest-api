<?php

namespace Tests\Feature\Api;

use App\Mail\CorporateEnquiryMail;
use App\Models\CorporateEnquiry;
use App\Models\SiteSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class CorporateEnquiryTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Rafi Ahmed',
            'company' => 'Northwind Ltd',
            'phone' => '01710000001',
            'email' => 'rafi@example.com',
            'quantity' => 40,
            'needed_by' => now()->addMonth()->toDateString(),
            'products_of_interest' => 'Tea sets',
            'message' => 'For our year-end gifts.',
        ], $overrides);
    }

    public function test_an_enquiry_is_saved_as_new(): void
    {
        $this->postJson('/api/corporate-enquiries', $this->payload())->assertCreated();

        $enquiry = CorporateEnquiry::sole();
        $this->assertSame('new', $enquiry->status);
        $this->assertSame('Northwind Ltd', $enquiry->company);
        $this->assertSame(40, $enquiry->quantity);
    }

    public function test_only_the_contact_details_and_quantity_are_required(): void
    {
        $this->postJson('/api/corporate-enquiries', [])->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'company', 'phone', 'email', 'quantity']);

        $this->postJson('/api/corporate-enquiries', $this->payload(['needed_by' => null, 'products_of_interest' => null, 'message' => null]))
            ->assertCreated();
    }

    public function test_the_quantity_and_date_are_checked(): void
    {
        $this->postJson('/api/corporate-enquiries', $this->payload(['quantity' => 0]))->assertJsonValidationErrors(['quantity']);
        $this->postJson('/api/corporate-enquiries', $this->payload(['needed_by' => now()->subDay()->toDateString()]))->assertJsonValidationErrors(['needed_by']);
        $this->postJson('/api/corporate-enquiries', $this->payload(['email' => 'not-an-email']))->assertJsonValidationErrors(['email']);
    }

    public function test_a_filled_honeypot_gets_the_same_answer_and_saves_nothing(): void
    {
        Mail::fake();
        SiteSetting::create(['site_name' => 'Anaiza Nest', 'corporate_notify_email' => 'team@example.com']);

        $this->postJson('/api/corporate-enquiries', $this->payload(['website' => 'http://spam.example']))
            ->assertCreated()
            ->assertJsonPath('message', 'Thank you. We have your enquiry and will be in touch.');

        $this->assertSame(0, CorporateEnquiry::count());
        Mail::assertNothingSent();
    }

    public function test_the_sixth_enquiry_in_an_hour_from_one_address_is_refused(): void
    {
        RateLimiter::clear('corporate-enquiries');

        foreach (range(1, 5) as $i) {
            $this->postJson('/api/corporate-enquiries', $this->payload())->assertCreated();
        }

        $this->postJson('/api/corporate-enquiries', $this->payload())->assertStatus(429);
        $this->assertSame(5, CorporateEnquiry::count());
    }

    public function test_the_team_is_emailed_at_the_address_in_site_settings(): void
    {
        Mail::fake();
        SiteSetting::create(['site_name' => 'Anaiza Nest', 'corporate_notify_email' => 'team@example.com']);

        $this->postJson('/api/corporate-enquiries', $this->payload())->assertCreated();

        Mail::assertSent(CorporateEnquiryMail::class, fn (CorporateEnquiryMail $mail) => $mail->hasTo('team@example.com')
            && $mail->hasReplyTo('rafi@example.com'));
    }

    public function test_no_email_is_sent_when_no_address_is_set(): void
    {
        Mail::fake();
        SiteSetting::create(['site_name' => 'Anaiza Nest']);

        $this->postJson('/api/corporate-enquiries', $this->payload())->assertCreated();

        Mail::assertNothingSent();
        $this->assertSame(1, CorporateEnquiry::count());
    }

    public function test_a_failing_email_is_logged_and_the_visitor_still_gets_a_success(): void
    {
        SiteSetting::create(['site_name' => 'Anaiza Nest', 'corporate_notify_email' => 'team@example.com']);
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP down'));
        Log::spy();

        $this->postJson('/api/corporate-enquiries', $this->payload())->assertCreated();

        Log::shouldHaveReceived('warning')->once();
        $this->assertSame(1, CorporateEnquiry::count());
    }

    public function test_the_intro_is_served_and_null_when_blank(): void
    {
        SiteSetting::create(['site_name' => 'Anaiza Nest']);
        $this->getJson('/api/site-settings')->assertJsonPath('data.corporate_intro', null);

        SiteSetting::query()->update(['corporate_intro' => '  Tell us what you need.  ']);
        $this->getJson('/api/site-settings')->assertJsonPath('data.corporate_intro', 'Tell us what you need.');
    }
}
