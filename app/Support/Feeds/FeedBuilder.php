<?php

namespace App\Support\Feeds;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\SiteSetting;
use App\Support\Pricing;
use Illuminate\Support\Collection;

/**
 * Turns the catalogue into the items of the Google, Meta and TikTok product feeds, and into the health report the admin sees.
 *
 * One item is one thing a shopper can buy. A product is an item (its id is its SKU, or its product id when it has none), and a
 * variant with a SKU of its own is an item too, grouped with its product by item_group_id. The same id is what the pixels send
 * (see feedId.ts in the storefront), because a dynamic product ad only works when the two match.
 *
 * Warnings never stop an item being listed. Only things that make it unlistable skip it: inactive, switched off for feeds,
 * no price, or out of stock when out-of-stock items are not wanted.
 */
final class FeedBuilder
{
    /** The strictest title limit among the three platforms (Google and TikTok); longer titles are cut for every feed. */
    public const TITLE_LIMIT = 150;

    public const DESCRIPTION_LIMIT = 5000;

    public const MAX_ADDITIONAL_IMAGES = 10;

    private SiteSetting $site;

    public function __construct(?SiteSetting $site = null)
    {
        $this->site = $site ?? (SiteSetting::query()->first() ?? new SiteSetting);
    }

    /**
     * @return array{items: list<array<string, mixed>>, health: list<array{product_id: int, name: string, status: string, reasons: list<string>}>}
     */
    public function build(): array
    {
        $items = [];
        $health = [];
        $seen = [];

        $products = Product::query()->with(['category', 'brand', 'images', 'variants.image'])->orderBy('id')->get();

        foreach ($products as $product) {
            $skip = $this->skipReason($product);
            $warnings = [];

            if ($skip === null) {
                [$productItems, $warnings] = $this->itemsFor($product, $seen);
                array_push($items, ...$productItems);
            }

            if ($skip !== null || $warnings !== []) {
                $health[] = [
                    'product_id' => $product->id,
                    'name' => $product->name,
                    'status' => $skip !== null ? 'Skipped' : 'Warning',
                    'reasons' => $skip !== null ? [$skip] : array_values(array_unique($warnings)),
                ];
            }
        }

        return ['items' => $items, 'health' => $health];
    }

    /**
     * The items one platform lists. TikTok leaves out a product that has variant items of its own, so a shopper sees only
     * what they can actually choose; Google and Meta keep it, grouped with its variants.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    public static function forPlatform(array $items, string $platform): array
    {
        return $platform === 'tiktok'
            ? array_values(array_filter($items, fn (array $item): bool => ! $item['is_parent_of_variants']))
            : $items;
    }

    private function skipReason(Product $product): ?string
    {
        if (! $product->is_active) {
            return 'Inactive, so not listed';
        }

        if ($product->exclude_from_feeds) {
            return 'Switched off for feeds';
        }

        if ((int) $product->price <= 0) {
            return 'No price';
        }

        if ($product->stock_quantity <= 0 && ! $this->site->feeds_include_out_of_stock && ! $this->sellableVariants($product)->contains(fn (ProductVariant $variant): bool => $variant->effective_stock > 0)) {
            return 'Out of stock (feeds are set to leave these out)';
        }

        return null;
    }

    /**
     * Variants that are items in their own right: those with a SKU that is theirs alone.
     *
     * @return Collection<int, ProductVariant>
     */
    private function sellableVariants(Product $product): Collection
    {
        return $product->variants->filter(fn (ProductVariant $variant): bool => filled(trim((string) $variant->sku)) && trim((string) $variant->sku) !== trim((string) $product->sku));
    }

    /**
     * @param  array<string, true>  $seen
     * @return array{0: list<array<string, mixed>>, 1: list<string>}
     */
    private function itemsFor(Product $product, array &$seen): array
    {
        $warnings = [];
        $variants = $this->sellableVariants($product);
        $hasVariantItems = $variants->isNotEmpty();
        $groupId = $hasVariantItems ? (string) $product->id : null;

        $title = FeedText::title($product->name, 1000);
        if (mb_strlen($title) > self::TITLE_LIMIT) {
            $warnings[] = 'Title is longer than '.self::TITLE_LIMIT.' characters, so it is cut';
        }

        $descriptionSource = FeedText::plain($product->summary ?: ($product->short_description ?: $product->description));
        if ($descriptionSource === '') {
            $warnings[] = 'No description, so the title is used';
        }

        if (blank($product->brand?->name)) {
            $warnings[] = 'No brand, so the shop name is used';
        }

        $category = $product->category;
        $base = [
            'description' => $descriptionSource !== '' ? FeedText::description($descriptionSource, self::DESCRIPTION_LIMIT) : FeedText::limit($title, self::DESCRIPTION_LIMIT),
            'link' => $this->site->feedStorefrontUrl().'/product/'.$product->slug,
            'brand' => FeedText::title($product->brand?->name ?: ($this->site->site_name ?: config('app.name')), 100),
            'gtin' => filled($product->gtin) ? preg_replace('/\D/', '', (string) $product->gtin) : null,
            'mpn' => filled($product->mpn) ? FeedText::title((string) $product->mpn, 70) : null,
            'item_group_id' => $groupId,
            'product_type' => FeedText::title(filled($category?->product_type) ? $category->product_type : ($category?->name ?? ''), 750),
            'google_product_category' => filled($category?->google_product_category) ? trim($category->google_product_category) : null,
            'custom_label_0' => FeedText::title($category?->name ?? '', 100),
            'custom_label_2' => $product->is_featured ? 'yes' : 'no',
        ];

        $productImages = $product->images->sortBy('sort_order')->values();
        $items = [];

        $entries = collect([[null, $product->sku]])->merge($variants->map(fn (ProductVariant $variant): array => [$variant, $variant->sku]));

        foreach ($entries as [$variant, $sku]) {
            $id = trim((string) $sku) !== '' ? trim((string) $sku) : (string) $product->id;

            if (isset($seen[$id])) {
                $warnings[] = "The id {$id} is already used by another item, so this one is left out";

                continue;
            }

            $price = (int) ($variant?->price ?? $product->price);
            $salePrice = $variant !== null && $variant->price !== null ? $variant->sale_price : $product->sale_price;
            $stock = $variant !== null ? $variant->effective_stock : $product->stock_quantity;

            if ($price <= 0) {
                $warnings[] = 'A variant has no price, so it is left out';

                continue;
            }

            if ($stock <= 0 && ! $this->site->feeds_include_out_of_stock) {
                continue;
            }

            [$images, $imageWarnings] = $this->images($variant?->image, $productImages);
            array_push($warnings, ...$imageWarnings);

            $seen[$id] = true;
            $hasSale = Pricing::saleApplies($salePrice !== null ? (int) $salePrice : null, $price);

            $items[] = $base + [
                'id' => $id,
                'title' => $variant !== null ? FeedText::limit(FeedText::title("{$product->name} - {$variant->name}: {$variant->value}", 1000), self::TITLE_LIMIT) : FeedText::limit($title, self::TITLE_LIMIT),
                'in_stock' => $stock > 0,
                'price' => $price,
                'sale_price' => $hasSale ? (int) $salePrice : null,
                'image_link' => $images[0] ?? null,
                'additional_image_link' => array_slice($images, 1, self::MAX_ADDITIONAL_IMAGES),
                'custom_label_1' => $hasSale ? 'yes' : 'no',
                'is_parent_of_variants' => $variant === null && $hasVariantItems,
            ];
        }

        // Meta and Google want a GTIN or an MPN, or to be told there is none. Google's own switch for that is identifier_exists.
        foreach ($items as $index => $item) {
            $items[$index]['identifier_exists'] = filled($item['gtin']) || filled($item['mpn']);
        }

        return [$items, $warnings];
    }

    /**
     * JPEG addresses for the item: its own image first (a variant's), then the product's, no repeats.
     *
     * @param  Collection<int, ProductImage>  $productImages
     * @return array{0: list<string>, 1: list<string>}
     */
    private function images(?ProductImage $own, Collection $productImages): array
    {
        $candidates = collect([$own])->merge($productImages)->filter()->unique('id')->values();
        $urls = [];
        $warnings = [];

        foreach ($candidates as $image) {
            $url = FeedImages::url($image);

            if ($url !== null) {
                $urls[] = $url;
            }

            $short = FeedImages::shortSide($image);
            if ($short !== null && $short < 500) {
                $warnings[] = 'An image is under 500px, which Meta and TikTok do not accept';
            }
        }

        if ($urls === []) {
            $warnings[] = 'No usable image (JPEG or PNG), so the item has none';
        }

        return [array_values(array_unique($urls)), $warnings];
    }
}
