<?php

namespace Tests\Feature\Api;

use App\Filament\Pages\ManageSiteSettings;
use App\Models\SiteSetting;
use App\Support\ImageSize;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\CourierFixtures;
use Tests\TestCase;

/**
 * The brand pictures in the public settings answer: the four logos and the default share image, each as a full https address with its size,
 * and null when nothing was uploaded. Nothing is redrawn or recoloured: the files are served as uploaded.
 */
class BrandPicturesApiTest extends TestCase
{
    use CourierFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['filesystems.disks.public.url' => 'https://api.anaizanest.example/storage']);
        Storage::forgetDisk('public');
        Storage::fake('public', ['url' => 'https://api.anaizanest.example/storage']);
    }

    private function png(string $path, int $width, int $height): void
    {
        Storage::disk('public')->put($path, UploadedFile::fake()->image('x.png', $width, $height)->getContent());
    }

    private function settings(): array
    {
        return $this->getJson('/api/site-settings')->assertOk()->json('data');
    }

    public function test_nothing_uploaded_is_null_everywhere(): void
    {
        $this->shop();

        $data = $this->settings();

        foreach (['logo_navy', 'logo_ivory', 'logo_mono', 'monogram', 'default_share_image'] as $field) {
            $this->assertNull($data[$field], $field);
            $this->assertSame(['url' => null, 'width' => null, 'height' => null], $data['brand'][$field], $field);
        }
    }

    public function test_each_picture_is_a_full_https_address_with_its_size(): void
    {
        $this->shop()->update([
            'logo_navy' => 'site/navy.png', 'logo_ivory' => 'site/ivory.png', 'logo_mono' => 'site/mono.png', 'monogram' => 'site/mono-gram.png', 'default_share_image' => 'site/share.png',
        ]);
        $this->png('site/navy.png', 480, 120);
        $this->png('site/ivory.png', 480, 120);
        $this->png('site/mono.png', 300, 80);
        $this->png('site/mono-gram.png', 256, 256);
        $this->png('site/share.png', 1200, 630);

        $data = $this->settings();

        $this->assertSame('https://api.anaizanest.example/storage/site/navy.png', $data['logo_navy']);
        $this->assertSame('https://api.anaizanest.example/storage/site/share.png', $data['default_share_image']);
        foreach (['logo_navy' => [480, 120], 'logo_ivory' => [480, 120], 'logo_mono' => [300, 80], 'monogram' => [256, 256], 'default_share_image' => [1200, 630]] as $field => [$width, $height]) {
            $this->assertStringStartsWith('https://', $data['brand'][$field]['url'], $field);
            $this->assertSame($data[$field], $data['brand'][$field]['url'], $field);
            $this->assertSame($width, $data['brand'][$field]['width'], $field);
            $this->assertSame($height, $data['brand'][$field]['height'], $field);
        }
    }

    public function test_an_address_typed_with_http_or_without_a_scheme_is_served_on_https_and_a_path_is_never_returned(): void
    {
        $this->shop()->update(['logo_navy' => 'http://cdn.example.com/navy.png', 'monogram' => '//cdn.example.com/m.png', 'logo_ivory' => 'site/ivory.png']);

        $data = $this->settings();

        $this->assertSame('https://cdn.example.com/navy.png', $data['logo_navy']);
        $this->assertSame('https://cdn.example.com/m.png', $data['monogram']);
        $this->assertNull($data['brand']['logo_navy']['width'], 'the size of a picture on another server is not fetched');
        $this->assertStringStartsWith('https://', $data['logo_ivory'], 'a stored path becomes a full address');
        $this->assertSame('https://api.anaizanest.example/storage/site/ivory.png', $data['logo_ivory']);
    }

    public function test_the_size_of_an_svg_comes_from_its_own_header(): void
    {
        Storage::disk('public')->put('site/a.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="320" height="90" viewBox="0 0 320 90"></svg>');
        Storage::disk('public')->put('site/b.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 50"></svg>');

        $this->assertSame(['width' => 320, 'height' => 90], ImageSize::of('site/a.svg'));
        $this->assertSame(['width' => 200, 'height' => 50], ImageSize::of('site/b.svg'));
        $this->assertSame(['width' => null, 'height' => null], ImageSize::of('site/missing.svg'));
        $this->assertSame(['width' => null, 'height' => null], ImageSize::of(null));
    }

    public function test_the_older_keys_the_storefront_reads_are_still_there(): void
    {
        $this->shop()->update(['logo_navy' => 'site/navy.png']);

        $data = $this->settings();

        foreach (['site_name', 'logo_url', 'logo_navy', 'logo_ivory', 'monogram', 'tagline'] as $key) {
            $this->assertArrayHasKey($key, $data);
        }
    }

    public function test_the_admin_has_the_share_image_upload_with_the_rebuild_sentence_on_it_and_on_the_monogram(): void
    {
        $this->withoutExceptionHandling();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->admin());
        $this->shop();

        $html = $this->get(ManageSiteSettings::getUrl())->assertSuccessful()->getContent();

        $this->assertStringContainsString('Default share image', $html);
        $this->assertSame(2, substr_count($html, 'A change here shows on the website after the site is rebuilt and uploaded.'));
        $this->assertStringContainsString('1200 by 630', $html);

        Livewire::test(ManageSiteSettings::class)->assertFormFieldExists('default_share_image')->assertFormFieldExists('monogram');
        $this->assertNull(SiteSetting::query()->first()->default_share_image, 'nothing was invented');
    }
}
