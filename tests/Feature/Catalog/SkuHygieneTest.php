<?php

namespace Tests\Feature\Catalog;

use App\Filament\Resources\ProductResource\Pages\EditProduct;
use App\Filament\Resources\ProductResource\RelationManagers\VariantsRelationManager;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Support\Sku;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The SKU is the id in the product feeds and the ad pixels, so it has one clean form: no stray "SKU " in front, no extra spaces, never empty, never shared.
 */
class SkuHygieneTest extends TestCase
{
    use RefreshDatabase;

    private function product(string $sku, string $name = 'Tea coaster set'): Product
    {
        static $n = 0;
        $n++;

        return Product::create([
            'category_id' => Category::firstOrCreate(['slug' => 'coasters'], ['name' => 'Coasters'])->id,
            'name' => $name, 'slug' => 'p-'.$n, 'price' => 600, 'stock_quantity' => 5, 'sku' => $sku, 'is_active' => true,
        ]);
    }

    /** Written straight into the table, as an older version of the shop (or an import) would have left it. */
    private function rawSku(Product|ProductVariant $row, ?string $sku): void
    {
        DB::table($row instanceof Product ? 'products' : 'product_variants')->where('id', $row->id)->update(['sku' => $sku]);
    }

    // ---- the rule

    public function test_the_rule_in_every_case(): void
    {
        foreach ([
            ['SKU ANZ-SG-002-SQ', 'ANZ-SG-002-SQ'],     // the one found in the audit
            ['sku ANZ-1', 'ANZ-1'],                     // any case
            ['SKU: ANZ-1', 'ANZ-1'],                    // with a colon
            ['Sku : ANZ-1', 'ANZ-1'],
            ['SKU-ANZ-1', 'ANZ-1'],                     // with a dash
            ['SKU - ANZ-1', 'ANZ-1'],
            ['  ANZ-1  ', 'ANZ-1'],                     // trimmed
            ["ANZ   TS\t1", 'ANZ TS 1'],                // inner whitespace made one space
            ['SKUNK-1', 'SKUNK-1'],                     // a SKU that merely starts with the letters
            ['ANZ-SKU-1', 'ANZ-SKU-1'],                 // the word inside is kept
            ['anz-ts-001', 'anz-ts-001'],               // the case of the rest is kept
            ['SKU', ''],                                // the word alone is nothing
            ['sku:', ''],
            ['   ', ''],
            ['', ''],
            [null, ''],
        ] as [$given, $expected]) {
            $this->assertSame($expected, Sku::clean($given), var_export($given, true));
        }
    }

    public function test_saving_a_product_or_a_variant_cleans_its_sku(): void
    {
        $product = $this->product('SKU ANZ-TS-001');
        $variant = $product->variants()->create(['name' => 'Shape', 'value' => 'Square', 'sku' => '  SKU: ANZ-TS-001-SQ ']);

        $this->assertSame('ANZ-TS-001', $product->fresh()->sku);
        $this->assertSame('ANZ-TS-001-SQ', $variant->fresh()->sku);

        $variant->update(['sku' => 'SKU']);
        $this->assertNull($variant->fresh()->sku, 'a variant whose SKU is nothing once cleaned has no SKU of its own');
    }

    public function test_empty_and_duplicate_skus_are_refused_with_a_clear_message(): void
    {
        $first = $this->product('ANZ-TS-001', 'Tea set');
        $second = $this->product('ANZ-TS-002', 'Mug');
        $variant = $second->variants()->create(['name' => 'Shape', 'value' => 'Square', 'sku' => 'ANZ-TS-002-SQ']);

        // a product needs one
        $this->assertStringContainsString('Enter a SKU', (string) Sku::problem('', false, $first->id));
        $this->assertStringContainsString('Enter a SKU', (string) Sku::problem('SKU', false, $first->id));
        // a variant may leave it out, but not type nothing-in-particular
        $this->assertNull(Sku::problem('', true, $second->id, $variant->id));
        $this->assertStringContainsString('Enter a SKU', (string) Sku::problem('SKU:', true, $second->id, $variant->id));

        // duplicates: another product, another product's variant, with a prefix, in another case
        $this->assertStringContainsString('already used by the product "Tea set"', (string) Sku::problem('ANZ-TS-001', false, $second->id));
        $this->assertStringContainsString('already used by the product "Tea set"', (string) Sku::problem('SKU ANZ-TS-001', false, $second->id));
        $this->assertStringContainsString('already used by the product "Tea set"', (string) Sku::problem('anz-ts-001', false, $second->id));
        $this->assertStringContainsString('already used by the variant "Shape: Square" of "Mug"', (string) Sku::problem('ANZ-TS-002-SQ', false, $first->id));
        $this->assertStringContainsString('already used by the variant', (string) Sku::problem('ANZ-TS-002-SQ', true, $first->id));

        // your own is fine
        $this->assertNull(Sku::problem('ANZ-TS-001', false, $first->id));
        $this->assertNull(Sku::problem('ANZ-TS-002-SQ', true, $second->id, $variant->id));
        // a variant may repeat its own product's SKU (it then has none of its own)
        $this->assertNull(Sku::problem('ANZ-TS-002', true, $second->id, $variant->id));
        // an unused one is fine
        $this->assertNull(Sku::problem('ANZ-TS-003', false, $first->id));
    }

    public function test_the_admin_variant_form_refuses_a_duplicate_and_saves_a_clean_one(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create(['role_id' => null]));
        $taken = $this->product('ANZ-TS-001', 'Tea set');
        $product = $this->product('ANZ-TS-002', 'Mug');

        $page = Livewire::test(VariantsRelationManager::class, ['ownerRecord' => $product, 'pageClass' => EditProduct::class]);

        $page->callTableAction('create', data: ['name' => 'Shape', 'value' => 'Square', 'sku' => 'SKU ANZ-TS-001'])->assertHasTableActionErrors(['sku']);
        $this->assertSame(0, $product->variants()->count());

        $page->callTableAction('create', data: ['name' => 'Shape', 'value' => 'Square', 'sku' => 'SKU: ANZ-TS-002-SQ'])->assertHasNoTableActionErrors();
        $this->assertSame('ANZ-TS-002-SQ', $product->variants()->firstOrFail()->sku);
        $this->assertNotNull($taken);
    }

    // ---- catalog:sku-audit

    public function test_the_audit_lists_what_would_change_and_changes_nothing_by_default(): void
    {
        $product = $this->product('ANZ-SG-002', 'Tea coaster set');
        $variant = $product->variants()->create(['name' => 'Shape', 'value' => 'Square', 'sku' => 'ANZ-SG-002-SQ']);
        $this->rawSku($variant, 'SKU ANZ-SG-002-SQ');
        $other = $this->product('ANZ-TS-001', 'Tea set');
        $this->rawSku($other, 'ANZ-TS-001 ');

        $exit = Artisan::call('catalog:sku-audit');
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('SKUs that the rules would change: 2', $output);
        $this->assertStringContainsString('Tea coaster set / Shape: Square', $output);
        $this->assertStringContainsString('"SKU ANZ-SG-002-SQ"', $output);
        $this->assertStringContainsString('"ANZ-SG-002-SQ"', $output);
        $this->assertStringContainsString('Dry run: nothing was changed', $output);
        $this->assertSame('SKU ANZ-SG-002-SQ', $variant->fresh()->sku);
        $this->assertSame('ANZ-TS-001 ', $other->fresh()->sku);
    }

    public function test_fix_applies_the_clean_up(): void
    {
        $product = $this->product('ANZ-SG-002', 'Tea coaster set');
        $variant = $product->variants()->create(['name' => 'Shape', 'value' => 'Square', 'sku' => 'ANZ-SG-002-SQ']);
        $this->rawSku($variant, 'SKU ANZ-SG-002-SQ');

        Artisan::call('catalog:sku-audit', ['--fix' => true]);

        $this->assertSame('ANZ-SG-002-SQ', $variant->fresh()->sku);
        $this->assertStringContainsString('Cleaned 1 SKU(s)', Artisan::output());

        Artisan::call('catalog:sku-audit');
        $this->assertStringContainsString('Nothing to clean', Artisan::output());
    }

    public function test_duplicates_are_listed_and_never_changed_for_you(): void
    {
        $a = $this->product('ANZ-TS-001', 'Tea set');
        $b = $this->product('ANZ-TS-002', 'Mug');
        $this->rawSku($b, 'SKU ANZ-TS-001');   // cleaned, it is the same as the tea set's

        $exit = Artisan::call('catalog:sku-audit');
        $output = Artisan::output();

        $this->assertSame(1, $exit, 'a duplicate makes the audit fail');
        $this->assertStringContainsString('more than one product or variant (once cleaned, case ignored): 1', $output);
        $this->assertStringContainsString('the product "Tea set"; the product "Mug"', $output);

        Artisan::call('catalog:sku-audit', ['--fix' => true]);

        $this->assertSame('SKU ANZ-TS-001', $b->fresh()->sku, 'left as it was');
        $this->assertStringContainsString('Left alone (it would be a duplicate)', Artisan::output());
        $this->assertSame('ANZ-TS-001', $a->fresh()->sku);
    }

    public function test_a_sku_that_is_nothing_once_cleaned_is_reported_for_a_person_to_fix(): void
    {
        $product = $this->product('ANZ-TS-001');
        $this->rawSku($product, 'SKU');

        $exit = Artisan::call('catalog:sku-audit', ['--fix' => true]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('nothing once cleaned', Artisan::output());
        $this->assertSame('SKU', $product->fresh()->sku);
    }
}
