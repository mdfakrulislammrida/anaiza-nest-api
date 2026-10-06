<?php

namespace App\Http\Resources;

use App\Support\HtmlSanitizer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PageResource extends JsonResource
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
            'title' => $this->title,
            'slug' => $this->slug,
            // An <h1> in the body is shown as an <h2>: the page title is the page's one H1.
            'content' => HtmlSanitizer::downgradeH1($this->content),
            'seo' => [
                'meta_title' => $this->meta_title ?: $this->title,
                'meta_description' => $this->meta_description,
                'og_image' => $this->og_image,
            ],
        ];
    }
}
