<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'payment_method' => $this->payment_method,
            // cod, awaiting_verification, verified or failed. The sender number, transaction ID and the
            // admin's note stay inside the admin.
            'payment_status' => $this->payment_status,
            // Set by the shop when the order ships; null until then.
            'courier_name' => $this->courier_name,
            'tracking_number' => $this->tracking_number,
            'subtotal' => $this->subtotal,
            'delivery_fee' => $this->delivery_fee,
            // What a coupon took off, and the code. Zero and null when none was used.
            'discount_amount' => (int) $this->discount_amount,
            'coupon_code' => $this->coupon_code,
            'total' => $this->total,
            'gift_note' => $this->gift_note,
            'is_gift' => (bool) $this->is_gift,
            'gift_message' => $this->is_gift ? $this->gift_message : null,
            'created_at' => $this->created_at,
            // Who it is for and where it goes, as typed when the order was placed (never a later order's details).
            'customer' => [
                'id' => $this->customer->id,
                'name' => $this->resource->recipient()['name'],
                'email' => $this->resource->recipient()['email'],
                'phone' => $this->resource->recipient()['phone'],
                'address' => $this->resource->recipient()['address'],
                'division' => $this->resource->recipient()['division'],
                'district' => $this->resource->recipient()['district'],
                'thana' => $this->resource->recipient()['thana'],
            ],
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'product_name' => $item->product_name,
                'quantity' => $item->quantity,
                'price' => $item->price,
                'variant_name' => $item->variant_name,
                'variant_value' => $item->variant_value,
                'sku' => $item->sku,
            ])),
        ];
    }
}
