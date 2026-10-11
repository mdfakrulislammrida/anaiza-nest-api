<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\ManageMarketingSettings;
use App\Filament\Pages\ManageProductFeeds;
use App\Models\Category;
use App\Models\MarketingSetting;
use App\Models\Product;
use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

class ProductFeedsAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        SiteSetting::create(['site_name' => 'Anaiza Nest', 'feed_storefront_url' => 'https://shop.example.test']);
        $this->actingAs(User::factory()->create(['role_id' => null]));
    }

    public function test_the_page_shows_the_three_addresses_and_the_health_table(): void
    {
        $category = Category::create(['name' => 'Mugs', 'slug' => 'mugs']);
        Product::create(['category_id' => $category->id, 'name' => 'Red mug', 'slug' => 'red-mug', 'price' => 600, 'stock_quantity' => 5, 'sku' => 'MUG-RED', 'is_active' => true]);
        $token = SiteSetting::first()->feedToken();

        Livewire::test(ManageProductFeeds::class)
            ->assertSee("/feeds/google.xml?token={$token}", false)
            ->assertSee("/feeds/meta.csv?token={$token}", false)
            ->assertSee("/feeds/tiktok.csv?token={$token}", false)
            ->assertSee('Red mug')
            ->assertSee('No usable image');
    }

    public function test_regenerating_the_token_changes_the_addresses_and_the_old_one_stops_working(): void
    {
        $old = SiteSetting::first()->feedToken();
        $this->get("/feeds/meta.csv?token={$old}")->assertOk();

        Livewire::test(ManageProductFeeds::class)->callAction('regenerateToken');

        $new = SiteSetting::first()->feed_token;
        $this->assertNotSame($old, $new);
        $this->get("/feeds/meta.csv?token={$old}")->assertNotFound();
        $this->get("/feeds/meta.csv?token={$new}")->assertOk();
    }

    public function test_the_test_event_codes_are_saved_with_the_marketing_settings(): void
    {
        MarketingSetting::create(['meta_pixel_id' => '123456789012345']);

        Livewire::test(ManageMarketingSettings::class)
            ->fillForm(['meta_test_event_code' => 'TEST12345', 'tiktok_test_event_code' => 'TEST67890'])
            ->call('save')
            ->assertHasNoFormErrors();

        $settings = MarketingSetting::first();
        $this->assertSame('TEST12345', $settings->meta_test_event_code);
        $this->assertSame('TEST67890', $settings->tiktok_test_event_code);
    }
}
