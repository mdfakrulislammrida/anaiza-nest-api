<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\MediaItemResource\Pages\CreateMediaItem;
use App\Filament\Resources\MediaItemResource\Pages\ListMediaItems;
use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class MediaLibraryTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role_id' => null]);
    }

    public function test_single_upload_captures_original_name_mime_and_size(): void
    {
        Storage::fake('public');
        $this->actingAs($this->admin());

        Livewire::test(CreateMediaItem::class)
            ->fillForm([
                'path' => UploadedFile::fake()->image('hero-banner.jpg', 200, 200),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $item = MediaItem::first();

        $this->assertNotNull($item);
        Storage::disk('public')->assertExists($item->path);
        $this->assertStringStartsWith('media-library/', $item->path);
        $this->assertSame('hero-banner.jpg', $item->original_name);
        $this->assertSame('image/jpeg', $item->mime_type);
        $this->assertGreaterThan(0, $item->size);
        $this->assertNotNull($item->uploaded_by);
    }

    public function test_bulk_upload_action_creates_one_item_per_file(): void
    {
        Storage::fake('public');
        $this->actingAs($this->admin());

        Livewire::test(ListMediaItems::class)
            ->mountTableAction('bulkUpload')
            ->setTableActionData([
                'files' => [
                    UploadedFile::fake()->image('one.jpg'),
                    UploadedFile::fake()->image('two.jpg'),
                ],
            ])
            ->callMountedTableAction();

        $this->assertSame(2, MediaItem::count());
        $names = MediaItem::pluck('original_name')->all();
        $this->assertContains('one.jpg', $names);
        $this->assertContains('two.jpg', $names);

        foreach (MediaItem::all() as $item) {
            Storage::disk('public')->assertExists($item->path);
        }
    }

    public function test_deleting_a_media_item_removes_the_underlying_file(): void
    {
        Storage::fake('public');

        $path = UploadedFile::fake()->image('to-delete.jpg')->store('media-library', 'public');
        $item = MediaItem::create([
            'disk' => 'public',
            'path' => $path,
            'original_name' => 'to-delete.jpg',
        ]);

        Storage::disk('public')->assertExists($path);

        $item->delete();

        Storage::disk('public')->assertMissing($path);
    }

    public function test_media_library_api_endpoint_returns_resolved_urls(): void
    {
        Storage::fake('public');

        $path = UploadedFile::fake()->image('catalog.jpg')->store('media-library', 'public');
        MediaItem::create([
            'disk' => 'public',
            'path' => $path,
            'original_name' => 'catalog.jpg',
            'mime_type' => 'image/jpeg',
            'size' => 12345,
        ]);

        $response = $this->getJson('/api/media-library');

        $response->assertOk();
        $url = $response->json('data.0.url');
        $this->assertStringContainsString('/storage/media-library/', $url);
        $this->assertSame('catalog.jpg', $response->json('data.0.original_name'));
    }
}
