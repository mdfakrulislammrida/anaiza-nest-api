<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MarketingSettingResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'gtm_container_id' => $this->gtm_container_id,
            'meta_pixel_id' => $this->meta_pixel_id,
            'ga4_id' => $this->ga4_id,
            'tiktok_pixel_id' => $this->tiktok_pixel_id,
        ];
    }
}
