<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Console\Command;

class FixSalePrices extends Command
{
    protected $signature = 'products:fix-sale-prices {--dry-run : List what would change without changing it}';

    protected $description = 'Set a sale price of 0 or less to blank (no sale) on products and variants, and list the ones changed.';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        $products = Product::query()->where('sale_price', '<=', 0)->orderBy('id')->get(['id', 'name', 'price', 'sale_price']);
        $variants = ProductVariant::query()->where('sale_price', '<=', 0)->with('product:id,name')->orderBy('id')->get(['id', 'product_id', 'name', 'value', 'sale_price']);

        foreach ($products as $product) {
            $this->line(($dry ? 'Would change' : 'Changed').": product #{$product->id} {$product->name} (sale price {$product->sale_price} -> none)");
        }

        foreach ($variants as $variant) {
            $this->line(($dry ? 'Would change' : 'Changed').": variant #{$variant->id} {$variant->product?->name} / {$variant->name}: {$variant->value} (sale price {$variant->sale_price} -> none)");
        }

        if (! $dry) {
            // A plain update, so nothing else about the row (not even updated_at) is touched by the model hooks.
            Product::query()->whereIn('id', $products->modelKeys())->toBase()->update(['sale_price' => null]);
            ProductVariant::query()->whereIn('id', $variants->modelKeys())->toBase()->update(['sale_price' => null]);
        }

        // Not changed, only reported: these already charge the regular price, but someone may want to look at them.
        $notLower = Product::query()->where('sale_price', '>', 0)->whereColumn('sale_price', '>=', 'price')->pluck('name');
        foreach ($notLower as $name) {
            $this->warn("Check: {$name} has a sale price that is not lower than its price, so it is not on sale.");
        }

        $this->info(($dry ? 'Would change ' : 'Changed ').$products->count().' product(s) and '.$variants->count().' variant(s).');

        return self::SUCCESS;
    }
}
