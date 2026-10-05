<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\ManageSiteSettings;
use App\Filament\Resources\CategoryResource\Pages\EditCategory;
use App\Models\Category;
use App\Models\SiteSetting;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CategoryMenuTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create(['role_id' => null]));
    }

    public function test_a_new_category_is_in_the_menu_by_default(): void
    {
        Category::create(['name' => 'Tea Sets', 'slug' => 'tea-sets']);

        $row = $this->getJson('/api/categories')->assertOk()->json('data.0');

        $this->assertTrue($row['show_in_menu']);
        $this->assertSame(0, $row['menu_order']);
    }

    public function test_the_category_list_is_ordered_by_menu_order_then_name(): void
    {
        Category::create(['name' => 'Zebra', 'slug' => 'zebra', 'menu_order' => 1]);
        Category::create(['name' => 'Beta', 'slug' => 'beta']);
        Category::create(['name' => 'Alpha', 'slug' => 'alpha']);
        Category::create(['name' => 'Gamma', 'slug' => 'gamma', 'menu_order' => 1]);

        $this->assertSame(
            ['Alpha', 'Beta', 'Gamma', 'Zebra'],
            array_column($this->getJson('/api/categories')->json('data'), 'name'),
        );
    }

    public function test_a_hidden_category_is_still_listed_but_flagged(): void
    {
        Category::create(['name' => 'Hidden', 'slug' => 'hidden', 'show_in_menu' => false]);

        $row = $this->getJson('/api/categories')->json('data.0');

        $this->assertFalse($row['show_in_menu']);
    }

    public function test_the_menu_switch_defaults_on(): void
    {
        $this->assertTrue($this->getJson('/api/site-settings')->json('data.show_categories_menu'));
    }

    public function test_the_menu_switch_follows_the_setting(): void
    {
        SiteSetting::create(['site_name' => 'Anaiza Nest', 'show_categories_menu' => false]);

        $this->assertFalse($this->getJson('/api/site-settings')->json('data.show_categories_menu'));
    }

    public function test_the_admin_can_hide_and_order_a_category(): void
    {
        $this->signIn();
        $category = Category::create(['name' => 'Tea Sets', 'slug' => 'tea-sets']);

        Livewire::test(EditCategory::class, ['record' => $category->getRouteKey()])
            ->fillForm(['show_in_menu' => false, 'menu_order' => 3])
            ->call('save')
            ->assertHasNoFormErrors();

        $category->refresh();
        $this->assertFalse($category->show_in_menu);
        $this->assertSame(3, $category->menu_order);
    }

    public function test_the_admin_can_switch_the_menu_off_in_site_settings(): void
    {
        $this->signIn();
        SiteSetting::create(['site_name' => 'Anaiza Nest']);

        Livewire::test(ManageSiteSettings::class)
            ->fillForm(['show_categories_menu' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertFalse(SiteSetting::first()->show_categories_menu);
    }
}
