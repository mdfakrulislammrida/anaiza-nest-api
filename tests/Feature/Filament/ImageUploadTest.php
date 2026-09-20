<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\BrandResource\Pages\CreateBrand;
use App\Models\Brand;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class ImageUploadTest extends TestCase
{
    use RefreshDatabase;

    public function test_uploading_a_brand_logo_stores_it_on_the_public_disk_and_the_api_serves_a_real_url(): void
    {
        Storage::fake('public');

        $admin = User::factory()->create(['role_id' => null]);
        $this->actingAs($admin);

        Livewire::test(CreateBrand::class)
            ->fillForm([
                'name' => 'Acme',
                'slug' => 'acme',
                'logo_url' => UploadedFile::fake()->image('logo.png'),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $brand = Brand::firstWhere('slug', 'acme');

        $this->assertNotNull($brand);
        $this->assertNotNull($brand->logo_url);

        // The stored value is a disk-relative path, not a raw URL.
        Storage::disk('public')->assertExists($brand->logo_url);
        $this->assertStringStartsWith('brands/', $brand->logo_url);

        // The public API resolves that path to a real, loadable URL.
        $response = $this->getJson('/api/brands');
        $response->assertOk();

        $returnedUrl = collect($response->json('data'))
            ->firstWhere('slug', 'acme')['logo_url'];

        $this->assertStringContainsString('/storage/brands/', $returnedUrl);
    }
}
