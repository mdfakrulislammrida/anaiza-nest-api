<?php

namespace App\Http\Resources;

use App\Support\MediaUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BannerResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $mobile = MediaUrl::resolve($this->mobile_image);

        return [
            'id' => $this->id,
            // Kept as it was: the largest desktop picture.
            'image_url' => MediaUrl::resolve($this->image_url),
            'headline' => $this->headline,
            'subtext' => $this->subtext,
            'link' => $this->link,
            'button_text' => $this->button_text,
            'sort_order' => $this->sort_order,
            // The desktop picture and its smaller copies, with its size when the shop converted it (older uploads have none).
            'desktop' => [
                'url' => MediaUrl::resolve($this->image_url),
                'width' => $this->image_width,
                'height' => $this->image_height,
                'url_1280' => MediaUrl::resolve($this->image_1280),
                'url_640' => MediaUrl::resolve($this->image_640),
            ],
            'mobile' => $mobile === null ? null : [
                'url' => $mobile,
                'width' => $this->mobile_width,
                'height' => $this->mobile_height,
                'auto_generated' => (bool) $this->mobile_auto_generated,
            ],
        ];
    }
}
