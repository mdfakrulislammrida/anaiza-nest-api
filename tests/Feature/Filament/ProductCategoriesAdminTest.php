<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\ProductResource\Pages\CreateProduct;
use App\Filament\Resources\ProductResource\Pages\EditProduct;
use App\Filament\Resources\ProductResource\Pages\ListProducts;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\AdminRecords;
use Tests\TestCase;

class ProductCategoriesAdminTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, Category> */
    private array $cat = [];

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create(['role_id' => null]));

        foreach (['Tea sets', 'Gifts', 'Ceramic'] as $name) {
            $this->cat[$name] = Category::create(['name' => $name, 'slug' => str($name)->slug()->toString()]);
        }
    }

    private function product(string $primary = 'Tea sets', array $extras = [], array $overrides = []): Product
    {
        $product = AdminRecords::product(['category_id' => $this->cat[$primary]->id] + $overrides);
        $product->syncCategories(array_map(fn (string $name): int => $this->cat[$name]->id, $extras));

        return $product->fresh();
    }

    private function listed(Product $product): array
    {
        return $product->categories()->pluck('categories.name')->sort()->values()->all();
    }

    public function test_the_form_has_a_required_primary_and_an_optional_extras_field_with_the_hint(): void
    {
        $product = $this->product();

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->assertFormFieldExists('category_id', fn ($field) => $field->isRequired() && $field->getLabel() === 'Primary category' && str_contains((string) $field->getHelperText(), 'breadcrumb and feeds'))
            ->assertFormFieldExists('extra_category_ids', fn ($field) => ! $field->isRequired() && $field->isMultiple() && $field->isSearchable() && $field->getLabel() === 'Also show in these categories');
    }

    public function test_the_extras_list_leaves_out_the_primary(): void
    {
        $product = $this->product('Gifts');

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->assertFormFieldExists('extra_category_ids', function ($field) {
                $options = $field->getOptions();

                return ! in_array('Gifts', $options, true) && in_array('Tea sets', $options, true) && in_array('Ceramic', $options, true);
            });
    }

    public function test_editing_loads_the_extras_without_the_primary_and_saves_primary_plus_extras(): void
    {
        $product = $this->product('Tea sets', ['Gifts']);

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->assertFormSet(['category_id' => $this->cat['Tea sets']->id, 'extra_category_ids' => [$this->cat['Gifts']->id]])
            ->fillForm(['extra_category_ids' => [$this->cat['Gifts']->id, $this->cat['Ceramic']->id]])
            ->call('save')
            ->assertHasNoFormErrors();

        $product->refresh();
        $this->assertSame($this->cat['Tea sets']->id, $product->category_id);
        $this->assertSame(['Ceramic', 'Gifts', 'Tea sets'], $this->listed($product));
    }

    public function test_clearing_the_extras_keeps_the_primary(): void
    {
        $product = $this->product('Tea sets', ['Gifts', 'Ceramic']);

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->fillForm(['extra_category_ids' => []])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(['Tea sets'], $this->listed($product->fresh()));
    }

    public function test_changing_the_primary_in_the_form_keeps_the_pivot_in_sync_and_the_old_primary_can_stay_as_an_extra(): void
    {
        $product = $this->product('Tea sets', ['Gifts']);

        // New primary Gifts (was an extra): it is no longer an extra of itself; Tea sets stays only because it is chosen as an extra.
        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->fillForm(['category_id' => $this->cat['Gifts']->id, 'extra_category_ids' => [$this->cat['Tea sets']->id]])
            ->call('save')
            ->assertHasNoFormErrors();

        $product->refresh();
        $this->assertSame($this->cat['Gifts']->id, $product->category_id);
        $this->assertSame(['Gifts', 'Tea sets'], $this->listed($product));
        $this->assertSame(2, DB::table('category_product')->where('product_id', $product->id)->count());

        // And moving it without keeping the old one: only the new primary.
        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->fillForm(['category_id' => $this->cat['Ceramic']->id, 'extra_category_ids' => []])
            ->call('save');

        $this->assertSame(['Ceramic'], $this->listed($product->fresh()));
    }

    public function test_a_new_product_saves_its_primary_and_extras(): void
    {
        Livewire::test(CreateProduct::class)
            ->fillForm([
                'category_id' => $this->cat['Tea sets']->id,
                'extra_category_ids' => [$this->cat['Gifts']->id, $this->cat['Ceramic']->id],
                'name' => 'Peacock Ceramic Tea Set with Caddy',
                'slug' => 'peacock-ceramic-tea-set-with-caddy',
                'price' => 4200,
                'stock_quantity' => 3,
                'sku' => 'PCT-1',
                'is_active' => true,
                // The repeaters start with one empty row whose fields are required.
                'box_contents' => [],
                'specifications' => [],
                'faqs' => [],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $product = Product::where('slug', 'peacock-ceramic-tea-set-with-caddy')->firstOrFail();
        $this->assertSame($this->cat['Tea sets']->id, $product->category_id);
        $this->assertSame(['Ceramic', 'Gifts', 'Tea sets'], $this->listed($product));
    }

    public function test_the_primary_is_required(): void
    {
        Livewire::test(CreateProduct::class)
            ->fillForm(['category_id' => null, 'name' => 'x', 'slug' => 'x', 'price' => 1, 'stock_quantity' => 1, 'sku' => 'X-1'])
            ->call('create')
            ->assertHasFormErrors(['category_id' => 'required']);
    }

    public function test_the_list_shows_the_primary_and_a_plus_n_badge_with_the_others_in_the_tooltip(): void
    {
        $many = $this->product('Tea sets', ['Gifts', 'Ceramic'], ['name' => 'Many', 'slug' => 'many']);
        $single = $this->product('Gifts', [], ['name' => 'Single', 'slug' => 'single']);

        $component = Livewire::test(ListProducts::class)->assertCanSeeTableRecords([$many, $single]);
        $table = $component->instance()->getTable();
        $badge = $table->getColumn('extra_categories');

        $badge->record($many->load('categories'));
        $this->assertSame('+2', $badge->getState());
        $this->assertSame('Ceramic, Gifts', $badge->getTooltip());

        $badge->record($single->load('categories'));
        $this->assertNull($badge->getState());
        $this->assertNull($badge->getTooltip());

        $component->assertTableColumnStateSet('category.name', 'Tea sets', $many)->assertTableColumnStateSet('category.name', 'Gifts', $single);
    }

    public function test_the_category_filter_matches_any_of_a_products_categories(): void
    {
        $inBoth = $this->product('Tea sets', ['Gifts'], ['name' => 'Both', 'slug' => 'both']);
        $primaryOnly = $this->product('Gifts', [], ['name' => 'Gift only', 'slug' => 'gift-only']);
        $other = $this->product('Ceramic', [], ['name' => 'Ceramic only', 'slug' => 'ceramic-only']);

        Livewire::test(ListProducts::class)
            ->filterTable('category_id', $this->cat['Gifts']->id)
            ->assertCanSeeTableRecords([$inBoth, $primaryOnly])
            ->assertCanNotSeeTableRecords([$other]);

        Livewire::test(ListProducts::class)
            ->filterTable('category_id', $this->cat['Tea sets']->id)
            ->assertCanSeeTableRecords([$inBoth])
            ->assertCanNotSeeTableRecords([$primaryOnly, $other]);
    }

    public function test_the_bulk_action_adds_to_a_category_and_never_touches_the_primary(): void
    {
        $one = $this->product('Tea sets', [], ['name' => 'One', 'slug' => 'one']);
        $two = $this->product('Gifts', [], ['name' => 'Two', 'slug' => 'two']);

        Livewire::test(ListProducts::class)
            ->callTableBulkAction('add_to_category', [$one, $two], ['category_id' => $this->cat['Gifts']->id])
            ->assertHasNoTableBulkActionErrors();

        $this->assertSame(['Gifts', 'Tea sets'], $this->listed($one->fresh()));
        $this->assertSame(['Gifts'], $this->listed($two->fresh()), 'Two already has Gifts as its primary: unchanged');
        $this->assertSame($this->cat['Tea sets']->id, $one->fresh()->category_id);
        $this->assertSame($this->cat['Gifts']->id, $two->fresh()->category_id);
    }

    public function test_the_bulk_action_removes_extras_only_and_never_the_primary(): void
    {
        $one = $this->product('Tea sets', ['Gifts', 'Ceramic'], ['name' => 'One', 'slug' => 'one']);
        $two = $this->product('Gifts', ['Ceramic'], ['name' => 'Two', 'slug' => 'two']);

        Livewire::test(ListProducts::class)
            ->callTableBulkAction('remove_from_category', [$one, $two], ['category_id' => $this->cat['Gifts']->id]);

        $this->assertSame(['Ceramic', 'Tea sets'], $this->listed($one->fresh()), 'the extra Gifts is gone');
        $this->assertSame(['Ceramic', 'Gifts'], $this->listed($two->fresh()), 'Gifts is Two\'s primary: kept');
        $this->assertSame($this->cat['Gifts']->id, $two->fresh()->category_id);

        Livewire::test(ListProducts::class)
            ->callTableBulkAction('remove_from_category', [$one, $two], ['category_id' => $this->cat['Ceramic']->id]);

        $this->assertSame(['Tea sets'], $this->listed($one->fresh()));
        $this->assertSame(['Gifts'], $this->listed($two->fresh()));
    }

    public function test_the_edit_page_and_list_render_for_a_phone(): void
    {
        $product = $this->product('Tea sets', ['Gifts']);

        $this->get('/admin/products/'.$product->getRouteKey().'/edit')->assertOk()->assertSee('Also show in these categories');
        $this->get('/admin/products')->assertOk();
    }
}
