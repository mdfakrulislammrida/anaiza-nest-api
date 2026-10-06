<?php

namespace App\Http\Resources;

use App\Support\MediaUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentSettingResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'bkash_number' => $this->bkash_number,
            'nagad_number' => $this->nagad_number,
            'rocket_number' => $this->rocket_number,
            'cod_enabled' => $this->cod_enabled,
            // The steps shown under each wallet at checkout (HTML, with {{amount}} and {{number}} filled in
            // by the storefront), and an optional logo; null means the storefront shows a plain text chip.
            'bkash_instructions' => $this->resource->instructionsFor('bkash'),
            'nagad_instructions' => $this->resource->instructionsFor('nagad'),
            'rocket_instructions' => $this->resource->instructionsFor('rocket'),
            'bkash_logo' => MediaUrl::resolve($this->bkash_logo),
            'nagad_logo' => MediaUrl::resolve($this->nagad_logo),
            'rocket_logo' => MediaUrl::resolve($this->rocket_logo),
        ];
    }
}
