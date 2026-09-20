<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Http\Request;

class CartController extends Controller
{
    public function index()
    {
        $cart = session('cart', []);
        $lines = [];
        $subtotal = 0;

        foreach ($cart as $key => $entry) {
            $product = Product::with('images')->find($entry['product_id']);

            if (! $product) {
                continue;
            }

            $variant = $entry['variant_id'] ? ProductVariant::find($entry['variant_id']) : null;
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

        return view('storefront.cart.index', compact('lines', 'subtotal'));
    }

    public function add(Request $request)
    {
        $validated = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
            'quantity' => ['required', 'integer', 'min:1'],
        ]);

        $product = Product::findOrFail($validated['product_id']);
        $variantId = $validated['variant_id'] ?? null;

        if ($variantId) {
            $belongsToProduct = ProductVariant::where('id', $variantId)
                ->where('product_id', $product->id)
                ->exists();

            abort_unless($belongsToProduct, 422, 'Invalid variant for this product.');
        }

        $key = $product->id.'-'.($variantId ?: 'none');
        $cart = session('cart', []);

        $newQuantity = ($cart[$key]['quantity'] ?? 0) + $validated['quantity'];
        $cart[$key] = [
            'product_id' => $product->id,
            'variant_id' => $variantId,
            'quantity' => min($newQuantity, $product->stock_quantity),
        ];

        session(['cart' => $cart]);

        return redirect()->route('storefront.cart.index')->with('success', $product->name.' added to your cart.');
    }

    public function update(Request $request, string $key)
    {
        $validated = $request->validate([
            'quantity' => ['required', 'integer', 'min:0'],
        ]);

        $cart = session('cart', []);

        if (! isset($cart[$key])) {
            return redirect()->route('storefront.cart.index');
        }

        if ($validated['quantity'] < 1) {
            unset($cart[$key]);
        } else {
            $cart[$key]['quantity'] = $validated['quantity'];
        }

        session(['cart' => $cart]);

        return redirect()->route('storefront.cart.index')->with('success', 'Cart updated.');
    }

    public function remove(string $key)
    {
        $cart = session('cart', []);
        unset($cart[$key]);
        session(['cart' => $cart]);

        return redirect()->route('storefront.cart.index')->with('success', 'Item removed.');
    }
}
