<?php

namespace Tests\Feature\Api;

use App\Models\ContactSubmission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactSubmissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_submitting_the_contact_form_creates_a_record(): void
    {
        $response = $this->postJson('/api/contact-submissions', [
            'name' => 'Nusrat J.',
            'phone' => '01710000000',
            'message' => 'Do you offer bulk gifting?',
        ]);

        $response->assertCreated();
        $this->assertSame(1, ContactSubmission::count());
        $this->assertFalse(ContactSubmission::first()->is_read);
    }

    public function test_contact_form_requires_name_phone_and_message(): void
    {
        $response = $this->postJson('/api/contact-submissions', []);

        $response->assertStatus(422)->assertJsonValidationErrors(['name', 'phone', 'message']);
    }
}
