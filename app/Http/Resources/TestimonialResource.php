<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TestimonialResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'customer_name' => $this->customer_name,
            'photo_url' => $this->photo_url,
            'quote' => $this->quote,
            'rating' => $this->rating,
            'is_featured' => $this->is_featured,
        ];
    }
}
