<?php

namespace App\Http\Resources;

use App\Support\MediaUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PopupSettingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'newsletter' => [
                'enabled' => $this->newsletter_enabled,
                'trigger' => $this->newsletter_trigger,
                'delay_seconds' => $this->newsletter_delay_seconds,
                'pages' => $this->newsletter_pages ?? [],
                'image' => MediaUrl::resolve($this->newsletter_image),
            ],
            'gift_finder' => [
                'enabled' => $this->giftfinder_enabled,
                'trigger' => $this->giftfinder_trigger,
                'delay_seconds' => $this->giftfinder_delay_seconds,
                'pages' => $this->giftfinder_pages ?? [],
                'image' => MediaUrl::resolve($this->giftfinder_image),
            ],
        ];
    }
}
