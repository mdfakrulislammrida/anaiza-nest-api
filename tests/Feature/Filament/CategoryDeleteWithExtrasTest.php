<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\CategoryResource\Pages\EditCategory;
use App\Filament\Resources\CategoryResource\Pages\ListCategories;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\AnaizaNestRealDataSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\AdminRecords;
use Tests\TestCase;

/**
 * Deleting a category once products can be listed in several: refused (and says which products) when it is the primary of any product; an extra-only
 * category is simply detached. Also: the seeder gives every product its primary pivot row.
 */
class CategoryDeleteWithExtrasTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create(['role_id' => null]));
    }

    private function lastNotification(): array
    {
        $all = session()->get('filament.notifications', []);

        return end($all) ?: [];
    }

    private function product(Category $primary, string $name): Product
    {
        return AdminRecords::product(['category_id' => $primary->id, 'name' => $name, 'slug' => str($name)->slug().'-'.uniqid()]);
    }

    public function test_a_category_that_is_the_primary_of_products_is_refused_and_names_them(): void
    {
        $gifts = AdminRecords::category(['name' => 'Gifts', 'slug' => 'gifts']);
        $this->product($gifts, 'Peacock set');
        $this->product($gifts, 'Burgundy cup');

        Livewire::test(ListCategories::class)->callTableAction('delete', $gifts);

        $this->assertDatabaseHas('categories', ['id' => $gifts->id]);
        $note = $this->lastNotification();
        $this->assertStringContainsString('Can\'t delete "Gifts"', $note['title']);
        $this->assertStringContainsString('2 products', $note['body']);
        $this->assertStringContainsString('"Burgundy cup", "Peacock set"', $note['body']);
        $this->assertStringContainsString('extra category', $note['body']);
        $this->assertSame(2, Product::count());
        $this->assertSame(2, DB::table('category_product')->where('category_id', $gifts->id)->count(), 'nothing was detached');
    }

    public function test_a_long_list_of_blocking_products_is_cut_with_a_count(): void
    {
        $gifts = AdminRecords::category(['name' => 'Gifts', 'slug' => 'gifts']);
        foreach (range(1, 8) as $n) {
            $this->product($gifts, "Product {$n}");
        }

        Livewire::test(EditCategory::class, ['record' => $gifts->getRouteKey()])->callAction('delete');

        $body = $this->lastNotification()['body'];
        $this->assertStringContainsString('8 products', $body);
        $this->assertStringContainsString('and 3 more', $body);
        $this->assertDatabaseHas('categories', ['id' => $gifts->id]);
    }

    public function test_a_category_that_is_only_an_extra_is_deleted_and_the_products_stay_with_their_primary(): void
    {
        $teaSets = AdminRecords::category(['name' => 'Tea sets', 'slug' => 'tea-sets']);
        $gifts = AdminRecords::category(['name' => 'Gifts', 'slug' => 'gifts']);
        $one = $this->product($teaSets, 'One');
        $two = $this->product($teaSets, 'Two');
        $one->syncCategories([$gifts->id]);
        $two->syncCategories([$gifts->id]);

        Livewire::test(ListCategories::class)->callTableAction('delete', $gifts);

        $this->assertDatabaseMissing('categories', ['id' => $gifts->id]);
        $this->assertSame(2, Product::count(), 'no product was deleted');
        $this->assertSame([$teaSets->id], $one->fresh()->categories()->pluck('categories.id')->all());
        $this->assertSame($teaSets->id, $two->fresh()->category_id);
        $this->assertSame(0, DB::table('category_product')->where('category_id', $gifts->id)->count());
    }

    public function test_bulk_delete_skips_primaries_names_them_and_detaches_the_extra_only_ones(): void
    {
        $blocked = AdminRecords::category(['name' => 'Has Primary', 'slug' => 'has-primary']);
        $extraOnly = AdminRecords::category(['name' => 'Extra Only', 'slug' => 'extra-only']);
        $empty = AdminRecords::category(['name' => 'Empty', 'slug' => 'empty']);
        $product = $this->product($blocked, 'Peacock set');
        $product->syncCategories([$extraOnly->id]);

        Livewire::test(ListCategories::class)->callTableBulkAction('delete', [$blocked, $extraOnly, $empty]);

        $this->assertDatabaseHas('categories', ['id' => $blocked->id]);
        $this->assertDatabaseMissing('categories', ['id' => $extraOnly->id]);
        $this->assertDatabaseMissing('categories', ['id' => $empty->id]);
        $this->assertSame([$blocked->id], $product->fresh()->categories()->pluck('categories.id')->all());
        $this->assertStringContainsString('"Peacock set"', $this->lastNotification()['body']);
    }

    public function test_the_real_data_seeder_gives_every_product_its_primary_row(): void
    {
        $this->seed(AnaizaNestRealDataSeeder::class);

        $this->assertGreaterThan(0, Product::count());

        foreach (Product::all() as $product) {
            $this->assertSame([$product->category_id], $product->categories()->pluck('categories.id')->all(), $product->name);
        }

        // Running it again changes nothing.
        $rows = DB::table('category_product')->count();
        $this->seed(AnaizaNestRealDataSeeder::class);
        $this->assertSame($rows, DB::table('category_product')->count());
    }
}
