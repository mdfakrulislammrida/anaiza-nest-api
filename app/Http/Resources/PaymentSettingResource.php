<?php

namespace App\Http\Resources;

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
            'cod_enabled' => $this->cod_enabled,
        ];
    }
}
