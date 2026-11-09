<?php

namespace Tests\Feature\Catalog;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\AdminRecords;
use Tests\TestCase;

class CategoryProductPivotTest extends TestCase
{
    use RefreshDatabase;

    private function categories(int $count = 3): array
    {
        return collect(range(1, $count))->map(fn (int $n) => Category::create(['name' => "Category {$n}", 'slug' => "category-{$n}"]))->all();
    }

    private function ids(Product $product): array
    {
        return $product->categories()->pluck('categories.id')->sort()->values()->all();
    }

    public function test_the_migration_backfills_one_row_per_existing_product_and_loses_nothing(): void
    {
        [$a, $b] = $this->categories(2);
        $one = AdminRecords::product(['category_id' => $a->id]);
        $two = AdminRecords::product(['category_id' => $a->id]);
        $three = AdminRecords::product(['category_id' => $b->id]);

        // The table as it was before the migration, then the migration itself.
        Schema::drop('category_product');
        $this->assertFalse(Schema::hasTable('category_product'));
        (require base_path('database/migrations/2026_11_09_100000_create_category_product_table.php'))->up();

        $this->assertSame(3, DB::table('category_product')->count());
        $this->assertSame([$a->id], DB::table('category_product')->where('product_id', $one->id)->pluck('category_id')->all());
        $this->assertSame([$a->id], DB::table('category_product')->where('product_id', $two->id)->pluck('category_id')->all());
        $this->assertSame([$b->id], DB::table('category_product')->where('product_id', $three->id)->pluck('category_id')->all());
        $this->assertSame(3, Product::count(), 'no product was touched');
        $this->assertSame($a->id, $one->fresh()->category_id, 'products.category_id keeps its meaning');
    }

    public function test_a_new_product_gets_a_row_for_its_primary_category(): void
    {
        [$a] = $this->categories(1);
        $product = AdminRecords::product(['category_id' => $a->id]);

        $this->assertSame([$a->id], $this->ids($product));
        $this->assertTrue($product->category->is($a));
        $this->assertTrue($product->primaryCategory->is($a));
    }

    public function test_changing_the_primary_moves_its_row_and_leaves_the_extras_alone(): void
    {
        [$a, $b, $c] = $this->categories();
        $product = AdminRecords::product(['category_id' => $a->id]);
        $product->syncCategories([$b->id]);
        $this->assertSame([$a->id, $b->id], $this->ids($product));

        $product->update(['category_id' => $c->id]);

        // A was the primary and is no longer listed (a product with one category that moves, moves); B stays; C, the new primary, is there.
        $this->assertSame([$b->id, $c->id], $this->ids($product->fresh()));
    }

    public function test_a_single_category_product_that_moves_is_listed_only_in_the_new_category(): void
    {
        [$a, $b] = $this->categories(2);
        $product = AdminRecords::product(['category_id' => $a->id]);

        $product->update(['category_id' => $b->id]);

        $this->assertSame([$b->id], $this->ids($product->fresh()));
        $this->assertSame(0, Product::inCategory($a)->count());
        $this->assertSame(1, Product::inCategory($b)->count());
    }

    public function test_choosing_the_primary_as_an_extra_does_not_double_it_and_removing_extras_never_removes_the_primary(): void
    {
        [$a, $b] = $this->categories(2);
        $product = AdminRecords::product(['category_id' => $a->id]);

        $product->syncCategories([$a->id, $b->id, $b->id, 0]);
        $this->assertSame([$a->id, $b->id], $this->ids($product));
        $this->assertSame(2, DB::table('category_product')->where('product_id', $product->id)->count());

        $product->syncCategories([]);
        $this->assertSame([$a->id], $this->ids($product));
    }

    public function test_the_same_pair_cannot_be_stored_twice(): void
    {
        [$a] = $this->categories(1);
        $product = AdminRecords::product(['category_id' => $a->id]);

        $this->expectException(QueryException::class);

        DB::table('category_product')->insert(['product_id' => $product->id, 'category_id' => $a->id]);
    }

    public function test_a_product_in_three_categories_is_found_once_in_each(): void
    {
        [$a, $b, $c] = $this->categories();
        $product = AdminRecords::product(['category_id' => $a->id]);
        $product->syncCategories([$b->id, $c->id]);
        $other = AdminRecords::product(['category_id' => $b->id]);

        foreach ([$a, $b, $c] as $category) {
            $this->assertTrue(Product::inCategory($category)->pluck('id')->contains($product->id));
        }

        $this->assertSame(1, Product::inCategory($a)->count());
        $this->assertSame(2, Product::inCategory($b)->count());
        $this->assertSame(1, Product::inCategory($c)->count());
        $this->assertSame([$product->id], Product::inCategory($c->id)->pluck('id')->all());
        $this->assertSame(2, Product::inCategorySlug('category-2')->count());
        $this->assertSame([], Product::inCategorySlug('nope')->pluck('id')->all());
        $this->assertNotNull($other);
    }

    public function test_deleting_a_product_removes_its_rows_and_a_category_that_is_only_an_extra_goes_without_the_product(): void
    {
        [$a, $b] = $this->categories(2);
        $product = AdminRecords::product(['category_id' => $a->id]);
        $product->syncCategories([$b->id]);

        $b->delete();

        $this->assertDatabaseHas('products', ['id' => $product->id]);
        $this->assertSame([$a->id], $this->ids($product->fresh()));

        $product->delete();
        $this->assertSame(0, DB::table('category_product')->count());
        $this->assertDatabaseHas('categories', ['id' => $a->id]);
    }

    public function test_a_category_that_is_the_primary_of_a_product_cannot_be_deleted_and_the_product_survives(): void
    {
        [$a] = $this->categories(1);
        $product = AdminRecords::product(['category_id' => $a->id]);

        try {
            $a->delete();
            $this->fail('the database should refuse');
        } catch (QueryException) {
            // expected
        }

        $this->assertDatabaseHas('products', ['id' => $product->id]);
        $this->assertSame([$a->id], $this->ids($product));
    }

    public function test_a_category_counts_each_product_once_whether_primary_or_extra(): void
    {
        [$a, $b] = $this->categories(2);
        $product = AdminRecords::product(['category_id' => $a->id]);
        $product->syncCategories([$b->id]);
        AdminRecords::product(['category_id' => $b->id]);

        $counts = Category::withCount(['products', 'listedProducts'])->orderBy('id')->get()->mapWithKeys(fn ($c) => [$c->id => [$c->products_count, $c->listed_products_count]]);

        $this->assertSame([1, 1], $counts[$a->id], 'A: one primary, one listed');
        $this->assertSame([1, 2], $counts[$b->id], 'B: one primary, two listed (its own and the extra)');
    }
}
