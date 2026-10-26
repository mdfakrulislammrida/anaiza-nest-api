<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\ManageSiteSettings;
use App\Filament\Resources\BannerResource\Pages\EditBanner;
use App\Filament\Resources\CategoryResource\Pages\EditCategory;
use App\Models\Banner;
use App\Models\SiteSetting;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\AdminRecords;
use Tests\TestCase;

/**
 * An observer turns an uploaded picture into a converted copy and removes the original on save. The page stays open, so a second
 * Save (a changed title, say) must not write the removed original's path back over the converted one.
 */
class UploadsSurviveASecondSaveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->actingAs(User::factory()->create(['role_id' => null]));
    }

    public function test_a_category_picture_survives_saving_again_without_reloading(): void
    {
        $category = AdminRecords::category();

        $page = Livewire::test(EditCategory::class, ['record' => $category->getRouteKey()])
            ->fillForm(['thumbnail' => UploadedFile::fake()->image('thumb.png', 800, 800), 'banner_desktop' => UploadedFile::fake()->image('banner.png', 1600, 500)])
            ->call('save')->assertHasNoFormErrors();

        $first = $category->fresh();
        $page->fillForm(['name' => 'Tea Sets, renamed'])->call('save')->assertHasNoFormErrors();
        $second = $category->fresh();

        foreach (['thumbnail', 'banner_desktop', 'banner_mobile'] as $field) {
            $this->assertNotNull($second->{$field}, $field);
            $this->assertSame($first->{$field}, $second->{$field}, "{$field} is kept as it was");
            Storage::disk('public')->assertExists($second->{$field});
        }
    }

    public function test_a_banner_picture_survives_saving_again_without_reloading(): void
    {
        $banner = AdminRecords::for(Banner::class);

        $page = Livewire::test(EditBanner::class, ['record' => $banner->getRouteKey()])
            ->fillForm(['image_url' => UploadedFile::fake()->image('slide.png', 1600, 600)])
            ->call('save')->assertHasNoFormErrors();

        $first = $banner->fresh();
        $page->fillForm(['title' => 'A new title'])->call('save')->assertHasNoFormErrors();
        $second = $banner->fresh();

        $this->assertSame($first->image_url, $second->image_url);
        Storage::disk('public')->assertExists($second->image_url);
        $this->assertSame($first->mobile_image, $second->mobile_image);
    }

    public function test_the_hero_pictures_survive_saving_again_without_reloading(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        SiteSetting::create(['site_name' => 'Anaiza Nest']);

        $page = Livewire::test(ManageSiteSettings::class)
            ->fillForm(['hero_image' => UploadedFile::fake()->image('hero.jpg', 2000, 1250), 'hot_deals_tile_image' => UploadedFile::fake()->image('tile.png', 1200, 750)])
            ->call('save')->assertHasNoFormErrors();

        $first = SiteSetting::first();
        $page->fillForm(['hero_image_alt' => 'A tea set'])->call('save')->assertHasNoFormErrors();
        $second = SiteSetting::first();

        $this->assertSame($first->hero_image, $second->hero_image);
        $this->assertSame($first->hot_deals_tile_image, $second->hot_deals_tile_image);
        Storage::disk('public')->assertExists($second->hero_image);
        $this->assertSame('A tea set', $second->hero_image_alt);
    }
}
