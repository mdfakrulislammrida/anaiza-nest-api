<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Coupons\CouponRejected;
use App\Support\Coupons\CouponService;
use App\Support\Dhaka;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CouponController extends Controller
{
    /**
     * What a code would take off this cart. A preview only: placing the order checks the code again, and that is the figure
     * that is charged.
     */
    public function validateCode(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:40'],
            'email' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'items' => ['required', 'array', 'min:1', 'max:60'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.variant_id' => ['nullable', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ]);

        $subtotal = CouponService::subtotalFor($data['items']);

        try {
            $result = CouponService::evaluate($data['code'], $subtotal, $data['email'] ?? null, $data['phone'] ?? null);
        } catch (CouponRejected $e) {
            return response()->json(['valid' => false, 'message' => $e->getMessage()], 422);
        }

        $coupon = $result['coupon'];

        return response()->json([
            'valid' => true,
            'code' => $coupon->code,
            'value_label' => $coupon->valueLabel(),
            'discount' => $result['discount'],
            'subtotal' => $subtotal,
            'valid_until' => $coupon->valid_until?->toIso8601String(),
            'valid_until_text' => $coupon->valid_until ? Dhaka::readable($coupon->valid_until) : null,
        ]);
    }
}
