<?php

namespace App\Console\Commands;

use App\Http\Controllers\FeedController;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\Sku;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CatalogSkuAudit extends Command
{
    protected $signature = 'catalog:sku-audit {--fix : Apply the clean-up (without it, nothing is changed)}';

    protected $description = 'List every product and variant SKU that the SKU rules would change (a stray "SKU " in front, extra spaces) and every SKU used twice. Changes nothing unless --fix is given.';

    public function handle(): int
    {
        $changes = [];
        $unusable = [];

        foreach (Product::query()->orderBy('id')->get(['id', 'name', 'sku']) as $product) {
            $clean = Sku::clean($product->sku);

            if ((string) $product->sku === $clean) {
                continue;
            }

            if ($clean === '') {
                $unusable[] = ['kind' => 'product', 'id' => $product->id, 'where' => $product->name, 'from' => (string) $product->sku];

                continue;
            }

            $changes[] = ['kind' => 'product', 'id' => $product->id, 'where' => $product->name, 'from' => (string) $product->sku, 'to' => $clean];
        }

        foreach (ProductVariant::query()->with('product:id,name')->orderBy('id')->get(['id', 'product_id', 'name', 'value', 'sku']) as $variant) {
            if ($variant->sku === null || (string) $variant->sku === Sku::clean($variant->sku)) {
                continue;
            }

            $clean = Sku::clean($variant->sku);
            $where = ($variant->product?->name ?? 'a product').' / '.$variant->name.': '.$variant->value;

            // A variant whose SKU is nothing once cleaned is simply a variant without a SKU of its own.
            $changes[] = ['kind' => 'variant', 'id' => $variant->id, 'where' => $where, 'from' => (string) $variant->sku, 'to' => $clean === '' ? null : $clean];
        }

        $duplicates = $this->duplicates();

        $this->line('SKUs that the rules would change: '.count($changes));
        if ($changes !== []) {
            $this->table(['Kind', 'Product / variant', 'Now', 'Would become'], array_map(fn (array $c): array => [$c['kind'], $c['where'], '"'.$c['from'].'"', $c['to'] === null ? '(none: takes the product\'s)' : '"'.$c['to'].'"'], $changes));
        }

        if ($unusable !== []) {
            $this->warn('SKUs that are nothing once cleaned (type a real SKU in the admin; --fix leaves them alone): '.count($unusable));
            $this->table(['Kind', 'Product', 'Now'], array_map(fn (array $u): array => [$u['kind'], $u['where'], '"'.$u['from'].'"'], $unusable));
        }

        $this->line('SKUs used by more than one product or variant (once cleaned, case ignored): '.count($duplicates));
        foreach ($duplicates as $sku => $owners) {
            $this->warn("  {$sku}: ".implode('; ', $owners));
        }

        if (! $this->option('fix')) {
            $this->info($changes === [] && $unusable === [] && $duplicates === [] ? 'Nothing to clean.' : 'Dry run: nothing was changed. Run with --fix to apply the clean-up (duplicates are never changed for you).');

            return $duplicates === [] && $unusable === [] ? self::SUCCESS : self::FAILURE;
        }

        $applied = 0;
        $skipped = 0;
        $collisions = array_map('mb_strtolower', array_keys($duplicates));

        foreach ($changes as $change) {
            if ($change['to'] !== null && in_array(mb_strtolower($change['to']), $collisions, true)) {
                $this->warn("Left alone (it would be a duplicate): {$change['where']}");
                $skipped++;

                continue;
            }

            DB::table($change['kind'] === 'product' ? 'products' : 'product_variants')->where('id', $change['id'])->update(['sku' => $change['to']]);
            $applied++;
        }

        FeedController::forgetAll();
        $this->info("Cleaned {$applied} SKU(s), left {$skipped} alone. The product feeds will be rebuilt on their next request.");

        return $skipped === 0 && $unusable === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * SKUs shared by two or more owners as they would be after cleaning.
     *
     * @return array<string, list<string>>
     */
    private function duplicates(): array
    {
        $groups = [];

        foreach (Sku::owners() as $owner) {
            $clean = Sku::clean($owner['sku']);

            if ($clean === '') {
                continue;
            }

            // A variant repeating its own product's SKU has none of its own: it is not a second owner.
            if ($owner['kind'] === 'variant' && mb_strtolower($clean) === mb_strtolower(Sku::clean((string) Product::query()->whereKey($owner['product_id'])->value('sku')))) {
                continue;
            }

            $groups[mb_strtolower($clean)][] = $owner['label'];
        }

        $result = [];
        foreach ($groups as $key => $labels) {
            if (count($labels) > 1) {
                $result[$key] = $labels;
            }
        }

        return $result;
    }
}
