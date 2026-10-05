<?php

namespace App\Http\Resources;

use App\Support\MediaUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The lightweight shape of a category: nested inside a product's response,
 * and returned by the /categories list endpoint for building nav/category
 * grids. The full banner/SEO/FAQ detail only makes sense on the category's
 * own page, and would just be dead weight repeated across every product or
 * every row of a category list -- see CategoryResource for that shape.
 */
class CategorySummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            // For the storefront's Categories menu.
            'show_in_menu' => (bool) $this->show_in_menu,
            'menu_order' => (int) $this->menu_order,
            'thumbnail' => [
                'url' => MediaUrl::resolve($this->thumbnail),
                'width' => $this->thumbnail_width,
                'height' => $this->thumbnail_height,
            ],
        ];
    }
}
