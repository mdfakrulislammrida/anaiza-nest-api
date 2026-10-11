<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AbandonedCheckout;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\Dhaka;
use App\Support\MediaUrl;
use Illuminate\Http\JsonResponse;

class CartRecoveryController extends Controller
{
    /**
     * A saved cart for the link in a reminder: each piece checked against the catalogue as it is now (price, stock), and
     * the checkout's coupon when it can still be used. Nothing is changed here; the storefront puts the pieces back.
     */
    public function show(string $token): JsonResponse
    {
        $checkout = AbandonedCheckout::query()->where('recovery_token', $token)->first();

        if ($checkout === null || $checkout->stage === 'ordered') {
            return response()->json(['found' => false], 404);
        }

        $lines = collect($checkout->items ?? []);
        $products = Product::query()->with(['images'])->whereIn('id', $lines->pluck('product_id'))->get()->keyBy('id');
        $variants = ProductVariant::query()->whereIn('id', $lines->pluck('variant_id')->filter())->get()->keyBy('id');

        $items = $lines->map(function (array $line) use ($products, $variants): array {
            $product = $products->get($line['product_id']);
            $variant = $line['variant_id'] ? $variants->get($line['variant_id']) : null;

            if ($variant && $product && $variant->product_id !== $product->id) {
                $variant = null;
            }

            if ($product === null || ! $product->is_active) {
                return $line + ['status' => 'unavailable', 'available' => 0, 'current_price' => null];
            }

            $variant?->setRelation('product', $product);
            $available = (int) ($variant?->effective_stock ?? $product->stock_quantity);
            $price = (int) ($variant?->effective_price ?? $product->effective_price);
            $wanted = (int) $line['quantity'];

            $status = match (true) {
                $available <= 0 => 'out_of_stock',
                $available < $wanted => 'reduced',
                $price !== (int) $line['price'] => 'price_changed',
                default => 'ok',
            };

            return [
                'product_id' => $product->id,
                'variant_id' => $variant?->id,
                'slug' => $product->slug,
                'name' => $product->name,
                'variant' => $variant ? $variant->name.': '.$variant->value : ($line['variant'] ?? null),
                'sku' => $variant?->effective_sku ?? $product->sku,
                'quantity' => $available > 0 ? min($wanted, $available) : 0,
                'wanted' => $wanted,
                'available' => $available,
                'price' => $price,
                'was_price' => (int) $line['price'],
                'image' => MediaUrl::resolve($product->images->first()?->url) ?? ($line['image'] ?? null),
                'status' => $status,
            ];
        })->values();

        $coupon = $checkout->coupon;
        $couponUsable = $coupon !== null && $coupon->is_active && ! ($coupon->single_use && $coupon->used_count > 0)
            && ($coupon->valid_until === null || $coupon->valid_until->isFuture());

        // Opening the link is recorded, so the admin can see the reminder was read.
        $messages = $checkout->messages ?? [];
        $messages['link_opened_at'] = now()->toIso8601String();
        $checkout->forceFill(['messages' => $messages])->saveQuietly();

        return response()->json([
            'found' => true,
            'name' => $checkout->name,
            'email' => $checkout->email,
            'phone' => $checkout->phone,
            'items' => $items,
            'coupon' => $couponUsable ? [
                'code' => $coupon->code,
                'value_label' => $coupon->valueLabel(),
                'valid_until_text' => $coupon->valid_until ? Dhaka::readable($coupon->valid_until) : null,
            ] : null,
            'coupon_ended' => $coupon !== null && ! $couponUsable,
        ]);
    }
}
