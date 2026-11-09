<?php

namespace App\Models;

use App\Models\Concerns\CleansContentHtml;
use App\Models\Concerns\RemembersSlugs;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    use RemembersSlugs;

    public static function slugType(): string
    {
        return 'category';
    }

    use CleansContentHtml;
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'banner_desktop',
        'banner_desktop_width',
        'banner_desktop_height',
        'banner_mobile',
        'banner_mobile_width',
        'banner_mobile_height',
        'banner_mobile_auto_generated',
        'thumbnail',
        'thumbnail_width',
        'thumbnail_height',
        'intro_text',
        'seo_description',
        'meta_title',
        'meta_description',
        'seo_image',
        'show_in_menu',
        'menu_order',
        'google_product_category',
        'product_type',
    ];

    /**
     * Mirrors the migration defaults as real in-PHP defaults (Eloquent does not re-read a
     * row after inserting it), so a category created without these reads as "in the menu".
     */
    protected $attributes = [
        'show_in_menu' => true,
        'menu_order' => 0,
    ];

    protected function casts(): array
    {
        return [
            'show_in_menu' => 'boolean',
            'menu_order' => 'integer',
            'banner_desktop_width' => 'integer',
            'banner_desktop_height' => 'integer',
            'banner_mobile_width' => 'integer',
            'banner_mobile_height' => 'integer',
            'banner_mobile_auto_generated' => 'boolean',
            'thumbnail_width' => 'integer',
            'thumbnail_height' => 'integer',
        ];
    }

    /** The products whose PRIMARY category this is (products.category_id). What blocks deleting a category. */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /** Every product listed in this category, as primary or extra (category_product), each once. What a category page and the counts use. */
    public function listedProducts(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'category_product')->withPivot('sort_order')->withTimestamps();
    }

    public function faqs(): HasMany
    {
        return $this->hasMany(CategoryFaq::class)->orderBy('sort_order');
    }

    protected function contentHtmlAttributes(): array
    {
        return ['seo_description'];
    }
}
