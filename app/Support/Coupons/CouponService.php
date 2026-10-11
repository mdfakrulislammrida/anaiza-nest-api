<?php

namespace App\Support\Coupons;

use App\Models\Coupon;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\Contact;
use App\Support\Dhaka;
use Illuminate\Support\Carbon;

/**
 * Coupon rules, in one place and only ever on the server: the checkout field asks here for a preview, and placing the
 * order asks here again (inside the order transaction) for the figure that is actually charged.
 *
 * A code works when it is switched on, inside its dates, not above its single use, meant for this buyer (when it is bound
 * to an email or phone), and the cart reaches its minimum. One code per order: nothing here ever adds two together.
 */
final class CouponService
{
    /** The alphabet for generated codes: no 0, O, 1, I or L, so a code read aloud or retyped is not mistaken. */
    private const ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    public static function normalize(?string $code): string
    {
        return mb_strtoupper(preg_replace('/\s+/', '', trim((string) $code)) ?? '');
    }

    /**
     * The coupon and the discount it gives a cart of this value, or a reason it cannot be used.
     *
     *
     * @return array{coupon: Coupon, discount: int}
     *
     * @throws CouponRejected
     */
    public static function evaluate(string $code, int $subtotal, ?string $email, ?string $phone, ?Carbon $now = null, bool $lock = false): array
    {
        $now ??= now();
        $query = Coupon::query()->where('code', self::normalize($code));
        $coupon = ($lock ? $query->lockForUpdate() : $query)->first();

        if ($coupon === null || ! $coupon->is_active) {
            throw new CouponRejected('We could not find that code. Please check it and try again.');
        }

        if ($coupon->valid_from !== null && $coupon->valid_from->greaterThan($now)) {
            throw new CouponRejected('That code starts on '.Dhaka::readable($coupon->valid_from).'.');
        }

        if ($coupon->valid_until !== null && $coupon->valid_until->lessThan($now)) {
            throw new CouponRejected('That code ended on '.Dhaka::readable($coupon->valid_until).'.');
        }

        if ($coupon->single_use && $coupon->used_count > 0) {
            throw new CouponRejected('That code has already been used.');
        }

        if ($coupon->isBound()) {
            $emailOk = filled($coupon->bound_email) && Contact::email($email) === $coupon->bound_email;
            $phoneOk = filled($coupon->bound_phone) && Contact::samePhone($phone, $coupon->bound_phone);

            if (Contact::email($email) === null && Contact::phone($phone) === null) {
                throw new CouponRejected('Please enter your email address or phone number first, so we can check this code is yours.');
            }

            if (! $emailOk && ! $phoneOk) {
                throw new CouponRejected('That code was made for a different email address or phone number.');
            }
        }

        if ($coupon->min_order !== null && $subtotal < $coupon->min_order) {
            throw new CouponRejected('That code applies to orders of ৳'.number_format($coupon->min_order).' or more.');
        }

        return ['coupon' => $coupon, 'discount' => self::discountFor($coupon, $subtotal)];
    }

    /** The taka taken off a cart of this value: never more than the cart, never fractional. */
    public static function discountFor(Coupon $coupon, int $subtotal): int
    {
        $discount = $coupon->discount_type === 'percent'
            ? intdiv($subtotal * min(100, $coupon->amount), 100)
            : $coupon->amount;

        return max(0, min($subtotal, $discount));
    }

    /**
     * The cart's value as the order will price it (no stock check, no locks): what a coupon preview is measured against.
     *
     * @param  list<array{product_id: int, variant_id?: int|null, quantity: int}>  $items
     */
    public static function subtotalFor(array $items): int
    {
        $products = Product::query()->whereIn('id', collect($items)->pluck('product_id'))->get()->keyBy('id');
        $variants = ProductVariant::query()->whereIn('id', collect($items)->pluck('variant_id')->filter())->get()->keyBy('id');
        $subtotal = 0;

        foreach ($items as $item) {
            $product = $products->get($item['product_id'] ?? null);
            if ($product === null) {
                continue;
            }

            $variant = ! empty($item['variant_id']) ? $variants->get($item['variant_id']) : null;
            if ($variant && $variant->product_id !== $product->id) {
                $variant = null;
            }
            $variant?->setRelation('product', $product);

            $subtotal += ($variant?->effective_price ?? $product->effective_price) * max(1, (int) ($item['quantity'] ?? 1));
        }

        return $subtotal;
    }

    /** A new, readable, unused code like ANZ-7K3M9. */
    public static function generateCode(string $prefix = 'ANZ'): string
    {
        do {
            $suffix = '';
            for ($i = 0; $i < 5; $i++) {
                $suffix .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
            $code = $prefix.'-'.$suffix;
        } while (Coupon::query()->where('code', $code)->exists());

        return $code;
    }

    /**
     * A coupon for one person: single use, bound to their email and phone, valid for the stated hours from now, and only
     * ever one per recipient per checkout.
     */
    public static function createFor(?string $email, ?string $phone, string $type, int $amount, ?int $minOrder, int $validHours, ?int $checkoutId = null, ?Carbon $now = null): Coupon
    {
        $now ??= now();

        return Coupon::query()->create([
            'code' => self::generateCode(),
            'discount_type' => $type === 'fixed' ? 'fixed' : 'percent',
            'amount' => $type === 'fixed' ? max(1, $amount) : max(1, min(100, $amount)),
            'is_active' => true,
            'valid_from' => $now,
            'valid_until' => $now->copy()->addHours(max(1, $validHours)),
            'min_order' => $minOrder ?: null,
            'single_use' => true,
            'bound_email' => Contact::email($email),
            'bound_phone' => Contact::phone($phone),
            'source' => Coupon::SOURCE_RECOVERY,
            'abandoned_checkout_id' => $checkoutId,
        ]);
    }

    /** "ends on 16 October 2026, 6:30 pm" wording helper for emails. */
    public static function expiryText(Coupon $coupon): string
    {
        return $coupon->valid_until ? Dhaka::readable($coupon->valid_until) : '';
    }
}
