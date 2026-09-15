<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOrderRequest;
use App\Http\Resources\OrderResource;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ShippingZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrderController extends Controller
{
    public function store(StoreOrderRequest $request)
    {
        $order = DB::transaction(function () use ($request) {
            $customer = Customer::where('email', $request->string('customer_email'))->first();

            $contactDetails = [
                'name' => $request->string('customer_name'),
                'phone' => $request->string('customer_phone'),
                'address' => $request->string('customer_address'),
                'city' => $request->string('customer_city'),
                'postal_code' => $request->string('customer_postal_code'),
            ];

            if ($customer) {
                // Keep the customer's address/contact details in sync with their latest order,
                // but never touch their password here.
                $customer->update($contactDetails);
            } else {
                $customer = Customer::create([
                    ...$contactDetails,
                    'email' => $request->string('customer_email'),
                    'password' => Str::random(32),
                ]);
            }

            $products = Product::query()
                ->whereIn('id', collect($request->input('items'))->pluck('product_id'))
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $subtotal = 0;
            $itemsToCreate = [];

            foreach ($request->input('items') as $item) {
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

                $lineTotal = $product->price * $item['quantity'];
                $subtotal += $lineTotal;

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

            $shippingZone = ShippingZone::findOrFail($request->integer('shipping_zone_id'));
            $deliveryFee = $shippingZone->delivery_fee;

            $order = Order::create([
                'customer_id' => $customer->id,
                'shipping_zone_id' => $shippingZone->id,
                'status' => 'pending',
                'subtotal' => $subtotal,
                'delivery_fee' => $deliveryFee,
                'total' => $subtotal + $deliveryFee,
                'payment_method' => $request->input('payment_method'),
                'gift_note' => $request->input('gift_note'),
            ]);

            $order->items()->createMany($itemsToCreate);

            return $order;
        });

        return OrderResource::make($order->load(['customer', 'items', 'shippingZone']))
            ->response()
            ->setStatusCode(201);
    }
}
