<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\TestimonialResource\Pages\CreateTestimonial;
use App\Models\Testimonial;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class TestimonialResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_a_testimonial_with_a_photo_upload_stores_it_on_the_public_disk(): void
    {
        Storage::fake('public');
        $this->actingAs(User::factory()->create(['role_id' => null]));

        Livewire::test(CreateTestimonial::class)
            ->fillForm([
                'customer_name' => 'Nusrat J.',
                'photo' => UploadedFile::fake()->image('nusrat.jpg'),
                'quote' => 'Beautiful pieces, arrived fast.',
                'rating' => 5,
                'is_featured' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $testimonial = Testimonial::first();

        $this->assertNotNull($testimonial);
        Storage::disk('public')->assertExists($testimonial->photo);
        $this->assertStringStartsWith('testimonials/', $testimonial->photo);
    }

    public function test_testimonials_api_endpoint_returns_resolved_photo_url_and_can_filter_featured(): void
    {
        Storage::fake('public');

        $photo = UploadedFile::fake()->image('a.jpg')->store('testimonials', 'public');

        Testimonial::create([
            'customer_name' => 'Featured Fan',
            'photo' => $photo,
            'quote' => 'Loved it.',
            'rating' => 5,
            'is_featured' => true,
        ]);
        Testimonial::create([
            'customer_name' => 'Regular Fan',
            'quote' => 'Good.',
            'rating' => 4,
            'is_featured' => false,
        ]);

        $response = $this->getJson('/api/testimonials');
        $response->assertOk()->assertJsonCount(2, 'data');

        $featuredOnly = $this->getJson('/api/testimonials?featured=1');
        $featuredOnly->assertOk()->assertJsonCount(1, 'data');
        $this->assertStringContainsString('/storage/testimonials/', $featuredOnly->json('data.0.photo_url'));
    }
}
