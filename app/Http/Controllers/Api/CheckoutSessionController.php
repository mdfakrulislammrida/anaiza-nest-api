<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Recovery\CheckoutTracker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CheckoutSessionController extends Controller
{
    /**
     * The storefront reports where a visitor has got to. Always answers 204: what the visitor did here is none of their
     * browser's business, and a problem here must never show up in checkout.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'session_token' => ['nullable', 'string', 'max:64'],
            'stage' => ['required', 'in:cart,checkout_started,contact_filled'],
            'count_only' => ['nullable', 'boolean'],
            'name' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'marketing_consent' => ['nullable', 'boolean'],
            'whatsapp_optin' => ['nullable', 'boolean'],
            'items' => ['nullable', 'array', 'max:'.CheckoutTracker::MAX_ITEMS],
            'items.*.product_id' => ['required_with:items', 'integer'],
            'items.*.variant_id' => ['nullable', 'integer'],
            'items.*.sku' => ['nullable', 'string', 'max:60'],
            'items.*.name' => ['nullable', 'string', 'max:150'],
            'items.*.variant' => ['nullable', 'string', 'max:100'],
            'items.*.price' => ['nullable', 'integer', 'min:0'],
            'items.*.quantity' => ['nullable', 'integer', 'min:1', 'max:99'],
            'items.*.image' => ['nullable', 'string', 'max:300'],
            'items.*.slug' => ['nullable', 'string', 'max:150'],
            'viewed_products' => ['nullable', 'array', 'max:20'],
            'viewed_products.*' => ['integer'],
            'utm_source' => ['nullable', 'string', 'max:150'],
            'utm_medium' => ['nullable', 'string', 'max:150'],
            'utm_campaign' => ['nullable', 'string', 'max:150'],
            'utm_content' => ['nullable', 'string', 'max:150'],
            'utm_term' => ['nullable', 'string', 'max:150'],
        ]);

        try {
            CheckoutTracker::report($data);
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json(null, 204);
    }
}
