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
            'subtotal' => $this->subtotal,
            'delivery_fee' => $this->delivery_fee,
            'total' => $this->total,
            'gift_note' => $this->gift_note,
            'created_at' => $this->created_at,
            'shipping_zone' => $this->whenLoaded('shippingZone', fn () => $this->shippingZone && [
                'id' => $this->shippingZone->id,
                'name' => $this->shippingZone->name,
                'estimated_days' => $this->shippingZone->estimated_days,
            ]),
            'customer' => [
                'id' => $this->customer->id,
                'name' => $this->customer->name,
                'email' => $this->customer->email,
                'phone' => $this->customer->phone,
                'address' => $this->customer->address,
                'city' => $this->customer->city,
                'postal_code' => $this->customer->postal_code,
            ],
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'product_name' => $item->product_name,
                'quantity' => $item->quantity,
                'price' => $item->price,
                'variant_name' => $item->variant_name,
                'variant_value' => $item->variant_value,
            ])),
        ];
    }
}
