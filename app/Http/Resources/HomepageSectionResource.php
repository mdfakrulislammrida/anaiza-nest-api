<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class HomepageSectionResource extends JsonResource
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
            'type' => $this->type,
            'position' => $this->position,
            'custom_title' => $this->custom_title,
            'custom_html' => $this->custom_html,
            // ISO 8601 with offset; only the Hot Deals section carries one.
            'deal_ends_at' => $this->type === 'hot_deals' ? $this->deal_ends_at?->toIso8601String() : null,
        ];
    }
}
