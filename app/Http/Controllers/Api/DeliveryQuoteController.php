<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\DeliveryFeeCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What delivery would cost for an address and a cart subtotal, from the same rule the order uses (so the checkout never
 * quotes a number the order will not charge). Read-only: it stores nothing and logs nothing.
 */
class DeliveryQuoteController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $data = $request->validate([
            'division' => ['nullable', 'string', 'max:60'],
            'district' => ['nullable', 'string', 'max:60'],
            'thana' => ['nullable', 'string', 'max:60'],
            'subtotal' => ['nullable', 'integer', 'min:0', 'max:100000000'],
        ]);

        return response()->json([
            'data' => DeliveryFeeCalculator::quote(
                $data['division'] ?? null,
                $data['district'] ?? null,
                $data['thana'] ?? null,
                (int) ($data['subtotal'] ?? 0),
            ),
        ]);
    }
}
