<?php

namespace App\Http\Resources;

use App\Support\HtmlSanitizer;
use App\Support\MediaUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
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
            // Downgraded here, once, server-side -- every consumer of this
            // API (today just the Next.js storefront) gets HTML that can
            // never introduce a second <h1>, regardless of what an admin
            // pastes into the rich editor.
            'description' => HtmlSanitizer::downgradeH1($this->description),
            'short_description' => $this->short_description,
            'summary' => $this->summary,
            // Only complete rows reach the storefront; a half-filled admin row is dropped.
            'specifications' => collect($this->specifications ?? [])
                ->filter(fn ($row) => filled($row['label'] ?? null) && filled($row['value'] ?? null))
                ->map(fn ($row) => ['label' => $row['label'], 'value' => $row['value']])
                ->values(),
            'gtin' => $this->gtin,
            'mpn' => $this->mpn,
            'price' => $this->price,
            'sale_price' => $this->sale_price,
            'effective_price' => $this->effective_price,
            'discount_percent' => $this->discount_percent,
            'stock_quantity' => $this->stock_quantity,
            'sku' => $this->sku,
            'is_new' => $this->is_new,
            'is_featured' => $this->is_featured,
            'is_active' => $this->is_active,
            'video' => [
                'url' => $this->video_url,
                'file' => MediaUrl::resolve($this->video_file),
                'poster' => MediaUrl::resolve($this->video_poster),
            ],
            'seo' => [
                'meta_title' => $this->meta_title ?: $this->name,
                'meta_description' => $this->meta_description,
                'og_image' => MediaUrl::resolve($this->og_image),
            ],
            'category' => CategorySummaryResource::make($this->whenLoaded('category')),
            'brand' => BrandResource::make($this->whenLoaded('brand')),
            'images' => $this->whenLoaded('images', fn () => $this->images->map(fn ($image) => [
                'id' => $image->id,
                'url' => $image->display_url,
                'url_400' => $image->display_url_400,
                'url_800' => $image->display_url_800,
                'width' => $image->width,
                'height' => $image->height,
                'alt_text' => $image->alt_text,
                'sort_order' => $image->sort_order,
            ])),
            'faqs' => $this->whenLoaded('faqs', fn () => $this->faqs->map(fn ($faq) => [
                'id' => $faq->id,
                'question' => $faq->question,
                'answer' => $faq->answer,
            ])),
            'variants' => $this->whenLoaded('variants', fn () => $this->variants->map(fn ($variant) => [
                'id' => $variant->id,
                'name' => $variant->name,
                'value' => $variant->value,
                'price' => $variant->price,
                'sale_price' => $variant->sale_price,
                'effective_price' => $variant->effective_price,
                'discount_percent' => $variant->discount_percent,
                'stock_quantity' => $variant->effective_stock,
                'sku' => $variant->effective_sku,
                'image' => $variant->image ? [
                    'id' => $variant->image->id,
                    'url' => $variant->image->display_url,
                    'url_400' => $variant->image->display_url_400,
                    'url_800' => $variant->image->display_url_800,
                    'width' => $variant->image->width,
                    'height' => $variant->image->height,
                    'alt_text' => $variant->image->alt_text,
                ] : null,
            ])),
            'tags' => $this->whenLoaded('tags', fn () => $this->tags->map(fn ($tag) => [
                'id' => $tag->id,
                'name' => $tag->name,
                'slug' => $tag->slug,
            ])),
            'labels' => $this->whenLoaded('labels', fn () => $this->labels->map(fn ($label) => [
                'id' => $label->id,
                'name' => $label->name,
                'badge_color' => $label->badge_color,
            ])),
            'attribute_values' => $this->whenLoaded('attributeValues', fn () => $this->attributeValues->map(fn ($value) => [
                'id' => $value->id,
                'attribute_name' => $value->attribute->name,
                'value' => $value->value,
            ])),
        ];
    }
}
