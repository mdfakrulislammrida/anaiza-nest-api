<?php

namespace App\Http\Controllers\Storefront;

use App\Actions\PlaceOrder;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CheckoutController extends Controller
{
    public function index()
    {
        $cart = session('cart', []);

        if (empty($cart)) {
            return redirect()->route('storefront.cart.index');
        }

        [$lines, $subtotal] = $this->buildLines($cart);

        return view('storefront.checkout.index', compact('lines', 'subtotal'));
    }

    public function store(Request $request, PlaceOrder $placeOrder)
    {
        $cart = session('cart', []);

        if (empty($cart)) {
            return redirect()->route('storefront.cart.index');
        }

        $validated = $request->validate([
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_email' => ['required', 'email', 'max:255'],
            'customer_phone' => ['required', 'string', 'max:30'],
            'customer_address' => ['required', 'string', 'max:500'],
            'customer_city' => ['required', 'string', 'max:120'],
            'customer_postal_code' => ['required', 'string', 'max:20'],
            'payment_method' => ['required', 'in:cod,bkash,nagad,card'],
            'gift_note' => ['nullable', 'string', 'max:1000'],
        ]);

        $items = collect($cart)->map(fn ($entry) => [
            'product_id' => $entry['product_id'],
            'quantity' => $entry['quantity'],
            'variant_id' => $entry['variant_id'],
        ])->values()->all();

        // Delivery fee is computed here, server-side, from the submitted city —
        // never trust a client-editable field for a money amount.
        $deliveryFee = str_contains(strtolower($validated['customer_city']), 'dhaka') ? 60 : 120;

        try {
            $order = $placeOrder->execute([
                ...$validated,
                'delivery_fee' => $deliveryFee,
                'items' => $items,
            ]);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors())->withInput();
        }

        session()->forget('cart');

        $order->load(['customer', 'items']);

        return view('storefront.checkout.confirmation', compact('order'));
    }

    private function buildLines(array $cart): array
    {
        $lines = [];
        $subtotal = 0;

        foreach ($cart as $key => $entry) {
            $product = \App\Models\Product::find($entry['product_id']);

            if (! $product) {
                continue;
            }

            $variant = $entry['variant_id'] ? \App\Models\ProductVariant::find($entry['variant_id']) : null;
            $lineTotal = $product->price * $entry['quantity'];
            $subtotal += $lineTotal;

            $lines[] = [
                'key' => $key,
                'product' => $product,
                'variant' => $variant,
                'quantity' => $entry['quantity'],
                'line_total' => $lineTotal,
            ];
        }

        return [$lines, $subtotal];
    }
}
