<?php

namespace App\Http\Resources;

use App\Support\ContentHtml;
use App\Support\Seo;
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
            'path' => $this->resource->path(),
            'previous_slugs' => $this->resource->previousSlugs(),
            // An <h1> in the body is shown as an <h2>: the page title is the page's one H1.
            'content' => ContentHtml::forOutput($this->content),
            'seo' => [
                'meta_title' => $this->meta_title ?: $this->title,
                'meta_description' => $this->meta_description,
                'og_image' => $this->og_image,
            ] + Seo::block($this->meta_title ?: $this->title, $this->meta_description, $this->og_image),
        ];
    }
}
