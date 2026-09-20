<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOrderRequest;
use App\Http\Resources\OrderResource;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\DeliveryFeeCalculator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrderController extends Controller
{
    public function store(StoreOrderRequest $request)
    {
        $order = DB::transaction(function () use ($request) {
            $customer = Customer::where('phone', $request->string('customer_phone'))->first();

            $contactDetails = [
                'name' => $request->string('customer_name'),
                'phone' => $request->string('customer_phone'),
                'address' => $request->string('customer_address'),
                'division' => $request->string('division'),
                'district' => $request->string('district'),
                'thana' => $request->string('thana'),
            ];

            if ($request->filled('customer_email')) {
                $contactDetails['email'] = $request->string('customer_email');
            }

            if ($customer) {
                // Keep the customer's address/contact details in sync with their latest order,
                // but never touch their password here.
                $customer->update($contactDetails);
            } else {
                $customer = Customer::create([
                    ...$contactDetails,
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

                $price = $product->effective_price;
                $lineTotal = $price * $item['quantity'];
                $subtotal += $lineTotal;

                $itemsToCreate[] = [
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'quantity' => $item['quantity'],
                    'price' => $price,
                    'variant_name' => $variant?->name,
                    'variant_value' => $variant?->value,
                ];

                $product->decrement('stock_quantity', $item['quantity']);
            }

            $deliveryFee = DeliveryFeeCalculator::forDivision($request->string('division')->toString(), $subtotal);

            $order = Order::create([
                'customer_id' => $customer->id,
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

        return OrderResource::make($order->load(['customer', 'items']))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Guest order tracking: an order is only returned when both the order
     * ID and the phone number on file for it match, so order IDs alone
     * (sequential integers) can't be used to browse other customers' orders.
     */
    public function lookup(Request $request)
    {
        $request->validate([
            'order_id' => ['required', 'integer'],
            'phone' => ['required', 'string'],
        ]);

        $order = Order::query()
            ->with(['customer', 'items'])
            ->where('id', $request->integer('order_id'))
            ->whereHas('customer', fn ($query) => $query->where('phone', $request->string('phone')))
            ->first();

        if (! $order) {
            return response()->json([
                'message' => 'No order found with that ID and phone number.',
            ], 404);
        }

        return OrderResource::make($order);
    }
}
