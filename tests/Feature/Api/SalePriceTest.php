<?php

namespace Tests\Feature\Api;

use App\Filament\Resources\ProductResource\Pages\EditProduct;
use App\Filament\Resources\ProductResource\RelationManagers\VariantsRelationManager;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\TestCase;

class SalePriceTest extends TestCase
{
    use RefreshDatabase;

    private function product(array $overrides = []): Product
    {
        static $n = 0;
        $n++;

        $product = Product::create(array_merge([
            'category_id' => Category::firstOrCreate(['slug' => 'glass'], ['name' => 'Glass'])->id,
            'name' => "Crystal glasses {$n}", 'slug' => "crystal-glasses-{$n}", 'price' => 1000,
            'stock_quantity' => 20, 'sku' => "GL-{$n}", 'is_active' => true,
        ], $overrides));

        // Rows written before the model guarded the value, or by an import, skip the model: write the raw column.
        if (array_key_exists('raw_sale', $overrides)) {
            DB::table('products')->where('id', $product->id)->update(['sale_price' => $overrides['raw_sale']]);
        }

        return $product->fresh();
    }

    private function order(Product $product, array $line = [], ?int $variantId = null): TestResponse
    {
        return $this->postJson('/api/orders', [
            'customer_name' => 'Nusrat Jahan', 'customer_phone' => '01712345678', 'customer_address' => 'House 12',
            'division' => 'Dhaka', 'district' => 'Dhaka', 'thana' => 'Banani', 'payment_method' => 'cod',
            'items' => [array_merge(['product_id' => $product->id, 'quantity' => 1] + ($variantId ? ['variant_id' => $variantId] : []), $line)],
        ]);
    }

    public function test_a_sale_price_of_zero_is_no_sale_in_the_api(): void
    {
        $product = $this->product(['raw_sale' => 0]);

        $this->getJson("/api/products/{$product->slug}")->assertOk()
            ->assertJsonPath('data.price', 1000)
            ->assertJsonPath('data.effective_price', 1000)
            ->assertJsonPath('data.on_sale', false)
            ->assertJsonPath('data.discount_percent', null);
    }

    public function test_negative_and_not_lower_sale_prices_are_no_sale_and_a_real_one_is(): void
    {
        foreach ([-5, 1000, 1200] as $raw) {
            $product = $this->product(['raw_sale' => $raw]);
            $this->assertFalse($product->on_sale, "sale {$raw}");
            $this->assertSame(1000, $product->effective_price);
            $this->assertNull($product->discount_percent);
        }

        $sale = $this->product(['sale_price' => 750]);
        $this->assertTrue($sale->on_sale);
        $this->assertSame(750, $sale->effective_price);
        $this->assertSame(25, $sale->discount_percent);

        $this->getJson("/api/products/{$sale->slug}")->assertJsonPath('data.on_sale', true)->assertJsonPath('data.discount_percent', 25);
    }

    public function test_the_model_stores_a_sale_price_of_zero_as_blank(): void
    {
        $product = $this->product(['sale_price' => 0]);
        $this->assertNull($product->sale_price);

        $variant = ProductVariant::create(['product_id' => $product->id, 'name' => 'Colour', 'value' => 'Red', 'price' => 900, 'sale_price' => 0]);
        $this->assertNull($variant->fresh()->sale_price);
    }

    public function test_variants_follow_the_same_rule(): void
    {
        $product = $this->product(['sale_price' => 800]);

        $own = ProductVariant::create(['product_id' => $product->id, 'name' => 'Size', 'value' => 'L', 'price' => 900]);
        DB::table('product_variants')->where('id', $own->id)->update(['sale_price' => 0]);
        $own = $own->fresh();
        $this->assertFalse($own->on_sale);
        $this->assertSame(900, $own->effective_price);
        $this->assertNull($own->discount_percent);

        $lower = ProductVariant::create(['product_id' => $product->id, 'name' => 'Size', 'value' => 'M', 'price' => 900, 'sale_price' => 450]);
        $this->assertTrue($lower->on_sale);
        $this->assertSame(450, $lower->effective_price);
        $this->assertSame(50, $lower->discount_percent);

        // No price of its own: it takes the product's own sale, and a sale price typed here alone does nothing.
        $inherits = ProductVariant::create(['product_id' => $product->id, 'name' => 'Size', 'value' => 'S', 'sale_price' => 100]);
        $this->assertSame(800, $inherits->effective_price);
    }

    public function test_an_order_is_priced_from_the_database_whatever_the_browser_sends(): void
    {
        $zeroSale = $this->product(['raw_sale' => 0]);

        $id = $this->order($zeroSale, ['price' => 1, 'effective_price' => 0, 'sale_price' => 0, 'total' => 1])->assertCreated()->json('data.id');
        $order = Order::with('items')->findOrFail($id);
        $this->assertSame(1000, (int) $order->items->first()->price);
        $this->assertSame(1000, (int) $order->subtotal);

        $onSale = $this->product(['sale_price' => 800]);
        $id = $this->order($onSale, ['price' => 1, 'sale_price' => 1])->assertCreated()->json('data.id');
        $this->assertSame(800, (int) Order::with('items')->findOrFail($id)->items->first()->price);
    }

    public function test_a_variant_order_is_priced_from_the_database_too(): void
    {
        $product = $this->product();
        $variant = ProductVariant::create(['product_id' => $product->id, 'name' => 'Size', 'value' => 'L', 'price' => 900]);
        DB::table('product_variants')->where('id', $variant->id)->update(['sale_price' => 0]);

        $id = $this->order($product, ['price' => 1], $variant->id)->assertCreated()->json('data.id');
        $this->assertSame(900, (int) Order::with('items')->findOrFail($id)->items->first()->price);
    }

    public function test_the_fix_command_blanks_zero_sale_prices_and_names_them(): void
    {
        $zero = $this->product(['name' => 'Red-stem crystal glasses, set of 2', 'raw_sale' => 0]);
        $real = $this->product(['name' => 'Real sale', 'sale_price' => 500]);
        $variantProduct = $this->product(['name' => 'Has variant']);
        $variant = ProductVariant::create(['product_id' => $variantProduct->id, 'name' => 'Size', 'value' => 'L', 'price' => 900]);
        DB::table('product_variants')->where('id', $variant->id)->update(['sale_price' => 0]);

        $this->artisan('products:fix-sale-prices', ['--dry-run' => true])
            ->expectsOutputToContain('Red-stem crystal glasses, set of 2')
            ->assertSuccessful();
        $this->assertSame(0, (int) DB::table('products')->where('id', $zero->id)->value('sale_price'));

        $this->artisan('products:fix-sale-prices')
            ->expectsOutputToContain('Red-stem crystal glasses, set of 2')
            ->expectsOutputToContain('Changed 1 product(s) and 1 variant(s).')
            ->assertSuccessful();

        $this->assertNull(DB::table('products')->where('id', $zero->id)->value('sale_price'));
        $this->assertNull(DB::table('product_variants')->where('id', $variant->id)->value('sale_price'));
        $this->assertSame(500, (int) DB::table('products')->where('id', $real->id)->value('sale_price'));
    }

    public function test_the_admin_product_form_rejects_a_bad_sale_price(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create(['role_id' => null]));
        $product = $this->product(['price' => 1000]);

        foreach ([0, -1, 1000, 1500] as $bad) {
            Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
                ->fillForm(['sale_price' => $bad])
                ->call('save')
                ->assertHasFormErrors(['sale_price']);
        }
        $this->assertNull($product->fresh()->sale_price);

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->fillForm(['sale_price' => 750])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame(750, $product->fresh()->sale_price);

        // Empty means no sale.
        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->fillForm(['sale_price' => null])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertNull($product->fresh()->sale_price);
    }

    public function test_the_admin_variant_form_rejects_a_bad_sale_price(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create(['role_id' => null]));
        $product = $this->product(['price' => 1000]);

        foreach ([['price' => 900, 'sale_price' => 0], ['price' => 900, 'sale_price' => 900], ['price' => null, 'sale_price' => 100]] as $bad) {
            Livewire::test(VariantsRelationManager::class, ['ownerRecord' => $product, 'pageClass' => EditProduct::class])
                ->callTableAction('create', data: ['name' => 'Size', 'value' => 'L'] + $bad)
                ->assertHasTableActionErrors(['sale_price']);
        }
        $this->assertSame(0, $product->variants()->count());

        Livewire::test(VariantsRelationManager::class, ['ownerRecord' => $product, 'pageClass' => EditProduct::class])
            ->callTableAction('create', data: ['name' => 'Size', 'value' => 'L', 'price' => 900, 'sale_price' => 700])
            ->assertHasNoTableActionErrors();
        $this->assertSame(700, $product->variants()->first()->sale_price);
    }
}
