<?php

namespace App\Http\Resources;

use App\Support\HtmlSanitizer;
use App\Support\MediaUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ArticleResource extends JsonResource
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
            // Same one-H1 rule as products and categories: the page title is the H1.
            'content' => HtmlSanitizer::downgradeH1($this->content),
            'featured_image' => MediaUrl::resolve($this->featured_image),
            'author' => [
                'name' => $this->author_name,
                'bio' => $this->author_bio,
            ],
            'published_at' => $this->published_at,
            'updated_at' => $this->updated_at,
            'seo' => [
                'meta_title' => $this->meta_title ?: $this->title,
                'meta_description' => $this->meta_description,
                'og_image' => MediaUrl::resolve($this->og_image),
            ],
        ];
    }
}
