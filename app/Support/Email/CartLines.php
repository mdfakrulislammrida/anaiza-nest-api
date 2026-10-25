<?php

namespace App\Support\Email;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\EmailThumbs;

/**
 * The lines of a saved cart, made ready for a cart email: each with its small picture, and a price that is never ৳0.
 * A saved cart holds what the browser sent, so a price of 0 (a sale price of 0 once did that) is replaced by what the piece
 * costs now, which follows App\Support\Pricing: a sale price of 0 is no sale, so it is the regular price.
 */
final class CartLines
{
    /**
     * @param  list<array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    public static function prepare(array $lines): array
    {
        return array_map(function (array $line): array {
            $product = isset($line['product_id']) ? Product::query()->find((int) $line['product_id']) : null;
            $variant = $product && ! empty($line['variant_id']) ? ProductVariant::query()->where('product_id', $product->id)->find((int) $line['variant_id']) : null;

            if ((int) ($line['price'] ?? 0) <= 0 && $product !== null) {
                $variant?->setRelation('product', $product);
                $line['price'] = (int) ($variant?->effective_price ?? $product->effective_price);
            }

            $line['thumb'] = EmailThumbs::url(EmailThumbs::snapshot($product, $variant));

            return $line;
        }, array_values($lines));
    }
}
