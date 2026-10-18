<?php

namespace Tests\Feature\Api;

use App\Models\SiteSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class HomepagePicturesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    /** Puts a picture of the given size on the public disk, where an admin upload lands, and returns its path. */
    private function upload(int $width, int $height, string $extension = 'jpg'): string
    {
        return UploadedFile::fake()->image("photo.{$extension}", $width, $height)->storeAs('site/uploads', "photo-{$width}x{$height}.{$extension}", 'public');
    }

    private function widthsOf(SiteSetting $settings, string $field): array
    {
        return array_column($settings->fresh()->{$field}, 'width');
    }

    private function generatedFiles(): array
    {
        return Storage::disk('public')->allFiles('site/generated');
    }

    public function test_a_large_picture_gets_three_webp_copies_and_the_original_is_deleted(): void
    {
        $path = $this->upload(2400, 1500);

        $settings = SiteSetting::create(['site_name' => 'Anaiza Nest', 'hero_image' => $path])->fresh();

        $this->assertSame([640, 1000, 1600], $this->widthsOf($settings, 'hero_image_renditions'));
        $this->assertCount(3, $this->generatedFiles());
        Storage::disk('public')->assertMissing($path);

        $copies = $settings->hero_image_renditions;
        $largest = end($copies);
        $this->assertSame($largest['path'], $settings->hero_image, 'the field holds the largest copy');
        $this->assertSame([1600, 1000], [$largest['width'], $largest['height']]);

        foreach ($settings->hero_image_renditions as $copy) {
            $disk = Storage::disk('public');
            $this->assertSame('image/webp', (new \finfo(FILEINFO_MIME_TYPE))->file($disk->path($copy['path'])));
            $this->assertSame([$copy['width'], $copy['height']], array_slice(getimagesize($disk->path($copy['path'])), 0, 2));
        }
    }

    public function test_a_picture_is_never_made_larger_than_it_is(): void
    {
        $settings = SiteSetting::create(['hero_image' => $this->upload(800, 500)]);
        $this->assertSame([640, 800], $this->widthsOf($settings, 'hero_image_renditions'));

        $settings->update(['hero_image' => $this->upload(400, 250)]);
        $this->assertSame([400], $this->widthsOf($settings, 'hero_image_renditions'));

        $settings->update(['hero_image' => $this->upload(1000, 625)]);
        $this->assertSame([640, 1000], $this->widthsOf($settings, 'hero_image_renditions'));
    }

    public function test_each_picture_has_its_own_set_of_widths(): void
    {
        $settings = SiteSetting::create([
            'hero_image' => $this->upload(3000, 1875),
            'hero_image_mobile' => $this->upload(1800, 2400),
            'hot_deals_tile_image' => $this->upload(2000, 1250),
            'new_arrivals_tile_image' => $this->upload(1000, 625),
        ])->fresh();

        $this->assertSame([640, 1000, 1600], array_column($settings->hero_image_renditions, 'width'));
        $this->assertSame([450, 900], array_column($settings->hero_image_mobile_renditions, 'width'));
        $this->assertSame([600, 1200], array_column($settings->hot_deals_tile_image_renditions, 'width'));
        $this->assertSame([600, 1000], array_column($settings->new_arrivals_tile_image_renditions, 'width'), 'a 1000 px tile picture stops at 1000');
    }

    public function test_a_png_and_a_webp_are_accepted(): void
    {
        $png = SiteSetting::create(['hero_image' => $this->upload(1200, 750, 'png')])->fresh();
        $this->assertNotNull($png->hero_image);

        $image = imagecreatetruecolor(1200, 750);
        ob_start();
        imagewebp($image);
        Storage::disk('public')->put('site/uploads/p.webp', ob_get_clean());
        $png->update(['hot_deals_tile_image' => 'site/uploads/p.webp']);

        $this->assertNotNull($png->fresh()->hot_deals_tile_image);
    }

    public function test_an_svg_a_gif_and_a_file_that_is_not_a_picture_are_refused_and_not_kept(): void
    {
        $disk = Storage::disk('public');
        $disk->put('site/uploads/logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="1600" height="1000"><rect width="1600" height="1000"/></svg>');
        $gif = imagecreatetruecolor(1600, 1000);
        ob_start();
        imagegif($gif);
        $disk->put('site/uploads/anim.gif', ob_get_clean());
        $disk->put('site/uploads/fake.jpg', 'this is not a picture');

        $settings = SiteSetting::create([
            'hero_image' => 'site/uploads/logo.svg',
            'hot_deals_tile_image' => 'site/uploads/anim.gif',
            'new_arrivals_tile_image' => 'site/uploads/fake.jpg',
        ])->fresh();

        $this->assertNull($settings->hero_image);
        $this->assertNull($settings->hot_deals_tile_image);
        $this->assertNull($settings->new_arrivals_tile_image);
        $this->assertNull($settings->hero_image_renditions);
        $this->assertSame([], $this->generatedFiles());
        $this->assertSame([], $disk->allFiles('site/uploads'), 'the uploads are deleted, not kept');
    }

    public function test_a_picture_over_five_megabytes_is_refused(): void
    {
        $disk = Storage::disk('public');
        $disk->put('site/uploads/big.jpg', $disk->get($this->upload(1600, 1000)).str_repeat("\0", 5 * 1024 * 1024));

        $settings = SiteSetting::create(['hero_image' => 'site/uploads/big.jpg'])->fresh();

        $this->assertNull($settings->hero_image);
        $this->assertSame([], $this->generatedFiles());
    }

    public function test_replacing_a_picture_deletes_the_old_copies_and_removing_it_clears_them(): void
    {
        $settings = SiteSetting::create(['hero_image' => $this->upload(1600, 1000)])->fresh();
        $first = array_column($settings->hero_image_renditions, 'path');

        $settings->update(['hero_image' => $this->upload(1200, 750)]);
        $second = array_column($settings->fresh()->hero_image_renditions, 'path');

        foreach ($first as $path) {
            Storage::disk('public')->assertMissing($path);
        }
        foreach ($second as $path) {
            Storage::disk('public')->assertExists($path);
        }

        $settings->update(['hero_image' => null]);

        $this->assertNull($settings->fresh()->hero_image);
        $this->assertNull($settings->fresh()->hero_image_renditions);
        $this->assertSame([], $this->generatedFiles());
    }

    public function test_saving_the_stored_value_again_changes_nothing(): void
    {
        $settings = SiteSetting::create(['hero_image' => $this->upload(1600, 1000)])->fresh();
        $before = $settings->hero_image_renditions;

        $settings->update(['hero_image' => $settings->hero_image, 'site_name' => 'Anaiza Nest']);

        $this->assertSame($before, $settings->fresh()->hero_image_renditions);
        $this->assertCount(3, $this->generatedFiles(), 'the same three copies, no new ones');
    }

    public function test_the_api_says_nothing_until_a_picture_is_uploaded(): void
    {
        SiteSetting::create(['site_name' => 'Anaiza Nest', 'hero_title' => 'Tea sets, packed by hand']);

        $images = $this->getJson('/api/site-settings')->assertOk()->json('data.homepage.images');

        $this->assertSame(['hero' => ['desktop' => null, 'mobile' => null, 'alt' => ''], 'hot_deals_tile' => ['image' => null, 'alt' => ''], 'new_arrivals_tile' => ['image' => null, 'alt' => '']], $images);
    }

    public function test_the_api_gives_absolute_urls_a_srcset_the_size_and_the_alt_text(): void
    {
        SiteSetting::create([
            'site_name' => 'Anaiza Nest',
            'hero_title' => 'Tea sets, packed by hand',
            'hero_image' => $this->upload(2400, 1500),
            'hero_image_mobile' => $this->upload(1200, 1600),
            'hero_image_alt' => '  Ivory tea set for two on a linen cloth  ',
            'hot_deals_tile_image' => $this->upload(1200, 750),
        ]);

        $images = $this->getJson('/api/site-settings')->assertOk()->json('data.homepage.images');

        $desktop = $images['hero']['desktop'];
        $this->assertStringStartsWith('http', $desktop['src']);
        $this->assertStringContainsString('/storage/site/generated/', $desktop['src']);
        $this->assertSame([1600, 1000], [$desktop['width'], $desktop['height']]);
        $this->assertMatchesRegularExpression('#^http\S+-640\.webp 640w, http\S+-1000\.webp 1000w, http\S+-1600\.webp 1600w$#', $desktop['srcset']);
        $this->assertStringEndsWith('1600w', $desktop['srcset']);

        $this->assertSame([900, 1200], [$images['hero']['mobile']['width'], $images['hero']['mobile']['height']]);
        $this->assertSame('Ivory tea set for two on a linen cloth', $images['hero']['alt']);
        $this->assertNotNull($images['hot_deals_tile']['image']);
        $this->assertNull($images['new_arrivals_tile']['image']);
    }

    public function test_an_empty_alt_stays_empty_and_is_never_the_headline(): void
    {
        SiteSetting::create([
            'site_name' => 'Anaiza Nest',
            'hero_title' => 'Tea sets, packed by hand',
            'hero_badge' => 'New in',
            'hero_image' => $this->upload(1600, 1000),
            'hero_image_alt' => null,
        ]);

        $this->assertSame('', $this->getJson('/api/site-settings')->json('data.homepage.images.hero.alt'));
    }

    public function test_the_wording_in_the_same_block_is_untouched(): void
    {
        SiteSetting::create(['site_name' => 'Anaiza Nest', 'hero_title' => 'Tea sets, packed by hand', 'hero_image' => $this->upload(1600, 1000)]);

        $homepage = $this->getJson('/api/site-settings')->json('data.homepage');

        $this->assertSame('Tea sets, packed by hand', $homepage['hero_title']);
        $this->assertNull($homepage['hero_badge']);
    }
}
