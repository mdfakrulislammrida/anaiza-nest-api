<?php

namespace App\Actions;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PlaceOrder
{
    /**
     * @param  array{
     *     customer_name: string,
     *     customer_email: string,
     *     customer_phone: string,
     *     customer_address: string,
     *     customer_city: string,
     *     customer_postal_code: string,
     *     payment_method: string,
     *     delivery_fee: int,
     *     gift_note: ?string,
     *     items: array<int, array{product_id: int, quantity: int, variant_id?: ?int}>,
     * }  $data
     */
    public function execute(array $data): Order
    {
        return DB::transaction(function () use ($data) {
            $customer = Customer::where('email', $data['customer_email'])->first();

            $contactDetails = [
                'name' => $data['customer_name'],
                'phone' => $data['customer_phone'],
                'address' => $data['customer_address'],
                'city' => $data['customer_city'],
                'postal_code' => $data['customer_postal_code'],
            ];

            if ($customer) {
                // Keep the customer's address/contact details in sync with their latest order,
                // but never touch their password here.
                $customer->update($contactDetails);
            } else {
                $customer = Customer::create([
                    ...$contactDetails,
                    'email' => $data['customer_email'],
                    'password' => Str::random(32),
                ]);
            }

            $products = Product::query()
                ->whereIn('id', collect($data['items'])->pluck('product_id'))
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $subtotal = 0;
            $itemsToCreate = [];

            foreach ($data['items'] as $item) {
                /** @var Product $product */
                $product = $products->get($item['product_id']);

                if ($product->stock_quantity < $item['quantity']) {
                    throw ValidationException::withMessages([
                        'items' => "Insufficient stock for {$product->name}.",
                    ]);
                }

                $variant = null;
                if (! empty($item['variant_id'])) {
                    $variant = ProductVariant::where('id', $item['variant_id'])
                        ->where('product_id', $product->id)
                        ->first();
                }

                $subtotal += $product->price * $item['quantity'];

                $itemsToCreate[] = [
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'quantity' => $item['quantity'],
                    'price' => $product->price,
                    'variant_name' => $variant?->name,
                    'variant_value' => $variant?->value,
                ];

                $product->decrement('stock_quantity', $item['quantity']);
            }

            $deliveryFee = $data['delivery_fee'];

            $order = Order::create([
                'customer_id' => $customer->id,
                'status' => 'pending',
                'subtotal' => $subtotal,
                'delivery_fee' => $deliveryFee,
                'total' => $subtotal + $deliveryFee,
                'payment_method' => $data['payment_method'],
                'gift_note' => $data['gift_note'] ?? null,
            ]);

            $order->items()->createMany($itemsToCreate);

            return $order;
        });
    }
}
