<?php

namespace App\Support;

use App\Models\Product;
use App\Models\ProductVariant;

/**
 * What a SKU may look like, and who owns it.
 *
 * The SKU is the id the shop gives a thing everywhere: the product feeds (Meta, Google, TikTok), the pixels' content_ids and the shop's own Purchase. They only
 * match a catalogue item when they are exactly the same text, so a stray "SKU " in front of a SKU, a double space or a trailing space makes a thing that can
 * never be matched. clean() is the one rule; it runs when a product or a variant is saved, and the feeds apply it again on the way out.
 */
final class Sku
{
    /** Trimmed, inner whitespace made one space, and a leading "SKU" word (with an optional ":" or "-" or spaces after it) removed. The case of the rest is kept. */
    public static function clean(?string $sku): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', (string) $sku) ?? '');

        // The word alone ("SKU", "sku:") is nothing.
        if (preg_match('/^SKU\s*[:\-]?$/iu', $text) === 1) {
            return '';
        }

        // "SKU ANZ-1", "SKU: ANZ-1", "sku-ANZ-1". Not a SKU that merely starts with these letters ("SKUNK-1").
        return trim(preg_replace('/^SKU(?:\s*[:\-]\s*|\s+)/iu', '', $text) ?? $text);
    }

    /**
     * Every SKU in the catalogue with its owner: products, and variants that have one.
     *
     * @return list<array{kind: string, id: int, product_id: int, label: string, sku: string}>
     */
    public static function owners(): array
    {
        $owners = [];

        foreach (Product::query()->get(['id', 'name', 'sku']) as $product) {
            if (trim((string) $product->sku) !== '') {
                $owners[] = ['kind' => 'product', 'id' => $product->id, 'product_id' => $product->id, 'label' => 'the product "'.$product->name.'"', 'sku' => (string) $product->sku];
            }
        }

        foreach (ProductVariant::query()->with('product:id,name')->get(['id', 'product_id', 'name', 'value', 'sku']) as $variant) {
            if (trim((string) $variant->sku) !== '') {
                $owners[] = [
                    'kind' => 'variant', 'id' => $variant->id, 'product_id' => $variant->product_id, 'sku' => (string) $variant->sku,
                    'label' => 'the variant "'.$variant->name.': '.$variant->value.'" of "'.($variant->product?->name ?? 'another product').'"',
                ];
            }
        }

        return $owners;
    }

    /**
     * The problem with a SKU someone typed, in plain words, or null when it is fine.
     *
     * A product must have one. A variant may leave it empty (it then takes its product's). Nothing else may share a SKU, compared without regard to case and
     * after cleaning, except that a variant may repeat its own product's SKU (which also means "no SKU of its own").
     */
    public static function problem(?string $sku, bool $isVariant, ?int $productId = null, ?int $variantId = null): ?string
    {
        $clean = self::clean($sku);

        if ($clean === '') {
            return trim((string) $sku) === '' && $isVariant ? null : 'Enter a SKU: letters, digits and dashes, for example ANZ-TS-001. (The word "SKU" on its own is not a SKU.)';
        }

        $wanted = mb_strtolower($clean);

        foreach (self::owners() as $owner) {
            if (mb_strtolower(self::clean($owner['sku'])) !== $wanted) {
                continue;
            }

            if ($owner['kind'] === 'product' && ! $isVariant && $owner['id'] === $productId) {
                continue;
            }

            if ($owner['kind'] === 'variant' && $isVariant && $owner['id'] === $variantId) {
                continue;
            }

            // A variant repeating its own product's SKU has none of its own: not a second owner.
            if ($isVariant && $owner['kind'] === 'product' && $owner['id'] === $productId) {
                continue;
            }

            return "The SKU {$clean} is already used by {$owner['label']}. Every product and variant needs its own, because the product feeds and the ad pixels identify things by it.";
        }

        return null;
    }
}
