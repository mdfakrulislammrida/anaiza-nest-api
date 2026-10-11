<?php

namespace Tests\Feature\Api;

use App\Filament\Resources\BannerResource;
use App\Filament\Resources\BannerResource\Pages\CreateBanner;
use App\Filament\Resources\BannerResource\Pages\ListBanners;
use App\Models\Banner;
use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class BannerSliderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    private function slide(array $attributes = []): Banner
    {
        static $n = 0;
        $n++;

        return Banner::create(array_merge(['image_url' => "https://cdn.example.test/slide-{$n}.jpg", 'headline' => "Slide {$n}", 'sort_order' => $n], $attributes));
    }

    private function slides(): array
    {
        return $this->getJson('/api/banners')->assertOk()->json();
    }

    public function test_only_live_slides_show_in_order_and_at_most_five(): void
    {
        Carbon::setTestNow('2026-10-14 12:00:00');

        $this->slide(['headline' => 'Off', 'is_active' => false]);
        $this->slide(['headline' => 'Not yet', 'starts_at' => now()->addDay()]);
        $this->slide(['headline' => 'Ended', 'ends_at' => now()->subDay()]);
        $this->slide(['headline' => 'Second', 'sort_order' => 20]);
        $this->slide(['headline' => 'First', 'sort_order' => 10, 'starts_at' => now()->subDay(), 'ends_at' => now()->addDay()]);

        $this->assertSame(['First', 'Second'], array_column($this->slides()['data'], 'headline'));

        foreach (range(1, 4) as $i) {
            $this->slide(['headline' => "Extra {$i}", 'sort_order' => 100 + $i]);
        }

        $this->assertCount(5, $this->slides()['data']);

        Carbon::setTestNow();
    }

    public function test_the_settings_default_to_no_autoplay_and_never_go_below_six_seconds(): void
    {
        $this->assertSame(['autoplay' => false, 'interval_seconds' => 7], $this->slides()['settings']);

        SiteSetting::create(['site_name' => 'Anaiza Nest', 'slider_autoplay' => true, 'slider_interval' => 3]);

        $this->assertSame(['autoplay' => true, 'interval_seconds' => 6], $this->slides()['settings']);
    }

    public function test_a_slide_carries_its_pictures_button_and_link(): void
    {
        $this->slide(['link' => '/shop', 'button_text' => 'See the range', 'image_width' => 1920, 'image_height' => 800, 'mobile_image' => 'https://cdn.example.test/m.jpg', 'mobile_width' => 750, 'mobile_height' => 500]);

        $slide = $this->slides()['data'][0];

        $this->assertSame('See the range', $slide['button_text']);
        $this->assertSame('/shop', $slide['link']);
        $this->assertSame(1920, $slide['desktop']['width']);
        $this->assertSame('https://cdn.example.test/m.jpg', $slide['mobile']['url']);
        $this->assertSame(750, $slide['mobile']['width']);
    }

    public function test_a_slide_without_a_mobile_picture_has_none_to_offer(): void
    {
        $this->slide();

        $this->assertNull($this->slides()['data'][0]['mobile']);
    }

    private function image(string $name, int $width, int $height): UploadedFile
    {
        return UploadedFile::fake()->image($name, $width, $height);
    }

    private function store(Banner $banner, string $field, UploadedFile $file): void
    {
        $banner->forceFill([$field => $file->store('banners', 'public')])->save();
    }

    public function test_the_desktop_picture_becomes_webp_copies_and_a_mobile_crop_is_made(): void
    {
        $banner = Banner::create(['image_url' => 'placeholder', 'headline' => 'Eid']);
        $this->store($banner, 'image_url', $this->image('slide.jpg', 2400, 1000));

        $banner->refresh();
        $disk = Storage::disk('public');

        $this->assertStringEndsWith('-1920.webp', $banner->image_url);
        $this->assertStringEndsWith('-1280.webp', $banner->image_1280);
        $this->assertStringEndsWith('-640.webp', $banner->image_640);
        $this->assertSame(1920, $banner->image_width);
        $this->assertSame(800, $banner->image_height);
        foreach ([$banner->image_url, $banner->image_1280, $banner->image_640, $banner->mobile_image] as $path) {
            $this->assertTrue($disk->exists($path), $path);
        }
        $this->assertTrue($banner->mobile_auto_generated);
        $this->assertSame([750, 500], [$banner->mobile_width, $banner->mobile_height]);
    }

    public function test_an_admin_mobile_picture_is_kept_when_the_desktop_picture_changes(): void
    {
        $banner = Banner::create(['image_url' => 'placeholder']);
        $this->store($banner, 'image_url', $this->image('slide.jpg', 1920, 800));
        $this->store($banner->refresh(), 'mobile_image', $this->image('phone.jpg', 1200, 800));

        $banner->refresh();
        $mine = $banner->mobile_image;

        $this->assertFalse($banner->mobile_auto_generated);
        $this->assertSame(900, $banner->mobile_width);

        $this->store($banner, 'image_url', $this->image('slide-2.jpg', 1920, 800));

        $this->assertSame($mine, $banner->refresh()->mobile_image);
    }

    public function test_a_narrow_desktop_picture_gets_no_larger_copies(): void
    {
        $banner = Banner::create(['image_url' => 'placeholder']);
        $this->store($banner, 'image_url', $this->image('small.jpg', 1000, 417));

        $banner->refresh();

        $this->assertNull($banner->image_1280);
        $this->assertNotNull($banner->image_640);
        $this->assertSame(1000, $banner->image_width);
    }

    public function test_the_admin_cannot_add_a_sixth_slide(): void
    {
        $this->actingAs(User::factory()->create(['role_id' => null]));

        $this->assertTrue(BannerResource::canCreate());

        foreach (range(1, Banner::MAX_SLIDES) as $i) {
            $this->slide();
        }

        $this->assertFalse(BannerResource::canCreate());
    }

    public function test_the_slider_settings_are_saved_from_the_list_page(): void
    {
        $this->actingAs(User::factory()->create(['role_id' => null]));

        Livewire::test(ListBanners::class)
            ->callAction('sliderSettings', ['slider_autoplay' => true, 'slider_interval' => 9])
            ->assertHasNoActionErrors();

        $site = SiteSetting::first();
        $this->assertTrue($site->slider_autoplay);
        $this->assertSame(9, $site->slider_interval);

        Livewire::test(ListBanners::class)
            ->callAction('sliderSettings', ['slider_autoplay' => true, 'slider_interval' => 3])
            ->assertHasActionErrors(['slider_interval']);
    }

    public function test_a_link_must_be_an_address_or_a_path_on_the_shop(): void
    {
        $this->actingAs(User::factory()->create(['role_id' => null]));

        Livewire::test(CreateBanner::class)
            ->fillForm(['image_url' => [$this->image('s.jpg', 1920, 800)], 'link' => 'javascript:alert(1)', 'sort_order' => 0, 'is_active' => true])
            ->call('create')
            ->assertHasFormErrors(['link']);
    }
}
