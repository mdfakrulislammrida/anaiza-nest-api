<?php

namespace App\Http\Resources;

use App\Support\ContentHtml;
use App\Support\MediaUrl;
use App\Support\Seo;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CategoryResource extends JsonResource
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
            'name' => $this->name,
            'slug' => $this->slug,
            // The page's address on the storefront, from the current slug, and the addresses it used to have (they still work and redirect here).
            'path' => $this->resource->path(),
            'previous_slugs' => $this->resource->previousSlugs(),
            'description' => $this->description,
            'intro_text' => $this->intro_text,
            // Downgraded here, once, server-side, same as Product::description
            // -- every consumer gets HTML that can never introduce a second
            // <h1>, regardless of what an admin pastes into the rich editor.
            'seo_description' => ContentHtml::forOutput($this->seo_description),
            'banner_desktop' => [
                'url' => MediaUrl::resolve($this->banner_desktop),
                'width' => $this->banner_desktop_width,
                'height' => $this->banner_desktop_height,
            ],
            'banner_mobile' => [
                'url' => MediaUrl::resolve($this->banner_mobile),
                'width' => $this->banner_mobile_width,
                'height' => $this->banner_mobile_height,
                'auto_generated' => $this->banner_mobile_auto_generated,
            ],
            'thumbnail' => [
                'url' => MediaUrl::resolve($this->thumbnail),
                'width' => $this->thumbnail_width,
                'height' => $this->thumbnail_height,
            ],
            // title, description and image are the one shape every page uses (App\Support\Seo); meta_title and meta_description are the older names for the same two texts.
            'seo' => [
                'meta_title' => $this->meta_title ?: $this->name,
                'meta_description' => $this->meta_description,
                'seo_image' => MediaUrl::resolve($this->seo_image),
            ] + Seo::block($this->meta_title ?: $this->name, $this->meta_description, $this->seo_image, $this->thumbnail, $this->banner_desktop),
            'faqs' => $this->whenLoaded('faqs', fn () => $this->faqs->map(fn ($faq) => [
                'id' => $faq->id,
                'question' => $faq->question,
                'answer' => $faq->answer,
            ])),
        ];
    }
}
