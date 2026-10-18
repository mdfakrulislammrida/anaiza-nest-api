<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\ManageSiteSettings;
use App\Models\SiteSetting;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class HomepagePicturesFormTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create(['role_id' => null]));
        SiteSetting::create(['site_name' => 'Anaiza Nest']);
    }

    private function page(): Testable
    {
        return Livewire::test(ManageSiteSettings::class);
    }

    public function test_the_section_and_its_helper_text_are_on_the_page(): void
    {
        $this->get('/admin/site-settings')
            ->assertOk()
            ->assertSee('Homepage pictures')
            ->assertSee('Hero picture, desktop')
            ->assertSee('Hero picture, mobile')
            ->assertSee('Special prices tile picture')
            ->assertSee('New arrivals tile picture')
            ->assertSee('Hero picture description')
            ->assertSee('Describe the picture in a few words, for example: Ivory tea set for two on a linen cloth. Leave empty if the picture is only decoration.')
            ->assertSee('keep the subject on the right', false)
            ->assertSee('keep the subject in the top part', false)
            ->assertSee('with no rebuild', false);
    }

    public function test_an_upload_is_converted_and_saved_with_its_alt_text(): void
    {
        $this->page()
            ->fillForm([
                'hero_image' => UploadedFile::fake()->image('hero.jpg', 2000, 1250),
                'hero_image_alt' => 'Ivory tea set for two on a linen cloth',
                'hot_deals_tile_image' => UploadedFile::fake()->image('tile.png', 1200, 750),
                'hot_deals_tile_image_alt' => '',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $settings = SiteSetting::first();
        $this->assertStringStartsWith('site/generated/', $settings->hero_image);
        $this->assertSame([640, 1000, 1600], array_column($settings->hero_image_renditions, 'width'));
        $this->assertSame([600, 1200], array_column($settings->hot_deals_tile_image_renditions, 'width'));
        $this->assertSame('Ivory tea set for two on a linen cloth', $settings->hero_image_alt);
        $this->assertSame([], Storage::disk('public')->allFiles('site/uploads'), 'the uploaded originals are gone');
    }

    public function test_saving_again_keeps_the_picture(): void
    {
        $page = $this->page()
            ->fillForm(['hero_image' => UploadedFile::fake()->image('hero.jpg', 1600, 1000)])
            ->call('save');

        $first = SiteSetting::first()->hero_image;

        $page->call('save')->assertHasNoFormErrors();

        $this->assertSame($first, SiteSetting::first()->hero_image, 'a second save with nothing changed keeps the picture');
        Storage::disk('public')->assertExists($first);
    }

    public function test_an_svg_and_a_gif_are_refused_by_the_form(): void
    {
        $svg = UploadedFile::fake()->createWithContent('hero.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="1600" height="1000"></svg>');
        $gif = UploadedFile::fake()->createWithContent('hero.gif', base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'));

        $this->page()->fillForm(['hero_image' => $svg])->call('save')->assertHasFormErrors(['hero_image']);
        $this->page()->fillForm(['hero_image_mobile' => $gif])->call('save')->assertHasFormErrors(['hero_image_mobile']);

        $this->assertNull(SiteSetting::first()->hero_image);
    }

    public function test_a_picture_over_five_megabytes_is_refused_by_the_form(): void
    {
        $big = UploadedFile::fake()->image('big.jpg', 1600, 1000)->size(5200);

        $this->page()->fillForm(['hero_image' => $big])->call('save')->assertHasFormErrors(['hero_image']);
    }

    public function test_the_alt_text_is_limited_to_160_characters(): void
    {
        $this->page()->fillForm(['hero_image_alt' => str_repeat('a', 161)])->call('save')->assertHasFormErrors(['hero_image_alt']);
        $this->page()->fillForm(['hero_image_alt' => str_repeat('a', 160)])->call('save')->assertHasNoFormErrors();
    }

    public function test_a_small_or_oddly_shaped_picture_gets_a_heads_up_and_is_still_kept(): void
    {
        $this->page()
            ->fillForm(['hero_image' => UploadedFile::fake()->image('small.jpg', 800, 800)])
            ->assertNotified('Picture size heads-up')
            ->call('save');

        $this->assertNotNull(SiteSetting::first()->hero_image);
    }

    public function test_removing_the_picture_returns_the_panel_to_plain_colour(): void
    {
        $page = $this->page()
            ->fillForm(['hero_image' => UploadedFile::fake()->image('hero.jpg', 1600, 1000)])
            ->call('save');

        $this->assertNotNull(SiteSetting::first()->hero_image);

        $page->fillForm(['hero_image' => null])->call('save')->assertHasNoFormErrors();

        $settings = SiteSetting::first();
        $this->assertNull($settings->hero_image);
        $this->assertNull($settings->hero_image_renditions);
        $this->assertSame([], Storage::disk('public')->allFiles('site/generated'));
        $this->assertNull($this->getJson('/api/site-settings')->json('data.homepage.images.hero.desktop'));
    }
}
