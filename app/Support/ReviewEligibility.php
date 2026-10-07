<?php

namespace App\Support;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductReview;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * Who may review a product: someone who ordered it, and only once per order line.
 *
 * Two ways to prove it: being signed in as a customer who has an order with the product in it, or giving an order number
 * together with the phone number on that order (the same pair the Track order page asks for). Orders that were cancelled,
 * or whose payment could not be matched, do not count.
 */
final class ReviewEligibility
{
    /**
     * The order line this review is for.
     *
     * @throws ValidationException
     */
    public static function lineFor(Product $product, ?Customer $customer, ?string $orderNumber, ?string $phone): OrderItem
    {
        $orders = $customer !== null
            ? Order::query()->where('customer_id', $customer->id)
            : self::orderFromNumberAndPhone($orderNumber, $phone);

        $lines = OrderItem::query()
            ->where('product_id', $product->id)
            ->whereIn('order_id', $orders->where('status', '!=', 'cancelled')->where('payment_status', '!=', 'failed')->select('orders.id'))
            ->orderBy('id')
            ->get();

        if ($lines->isEmpty()) {
            throw ValidationException::withMessages([
                $customer !== null ? 'rating' : 'order_number' => $customer !== null
                    ? 'Reviews come from people who have ordered this piece. We could not find it in your orders.'
                    : 'We could not match that order number and phone number to an order with this piece in it. Please check them and try again.',
            ]);
        }

        $reviewed = ProductReview::query()->whereIn('order_item_id', $lines->pluck('id'))->pluck('order_item_id')->all();
        $open = $lines->first(fn (OrderItem $line): bool => ! in_array($line->id, $reviewed, true));

        if ($open === null) {
            throw ValidationException::withMessages([
                'rating' => 'You have already reviewed this piece from that order. Thank you.',
            ]);
        }

        return $open;
    }

    /**
     * The orders matching the number and phone, as a query (empty when either is missing or wrong).
     *
     * @return Builder<Order>
     */
    private static function orderFromNumberAndPhone(?string $orderNumber, ?string $phone)
    {
        $query = Order::query();

        if (blank($orderNumber) || blank($phone) || ! ctype_digit(trim((string) $orderNumber))) {
            return $query->whereRaw('1 = 0');
        }

        $order = Order::query()->with('customer')->find((int) trim((string) $orderNumber));

        // Compared as phone numbers, not as text: +8801712345678, 8801712345678 and 01712345678 are the same number.
        if ($order === null || ! self::samePhone((string) $order->customer?->phone, (string) $phone)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('orders.id', $order->id);
    }

    private static function samePhone(string $stored, string $given): bool
    {
        $normalize = fn (string $value): string => (string) WalletPayments::normalizeNumber(preg_replace('/[^0-9+]/', '', $value) ?? '');

        return $normalize($stored) !== '' && $normalize($stored) === $normalize($given);
    }
}
