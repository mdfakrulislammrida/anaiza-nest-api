<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'category_id',
        'brand_id',
        'name',
        'slug',
        'description',
        'short_description',
        'summary',
        'specifications',
        'box_contents',
        'care_instructions',
        'gift_box_included',
        'meta_title',
        'meta_description',
        'og_image',
        'video_url',
        'video_file',
        'video_poster',
        'price',
        'sale_price',
        'stock_quantity',
        'sku',
        'gtin',
        'mpn',
        'is_new',
        'is_featured',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'sale_price' => 'integer',
            'stock_quantity' => 'integer',
            'specifications' => 'array',
            'box_contents' => 'array',
            'gift_box_included' => 'boolean',
            'is_new' => 'boolean',
            'is_featured' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * The price to actually charge and display: the sale price when one is
     * set (and lower than the regular price), otherwise the regular price.
     */
    public function getEffectivePriceAttribute(): int
    {
        if ($this->sale_price !== null && $this->sale_price < $this->price) {
            return $this->sale_price;
        }

        return $this->price;
    }

    public function getDiscountPercentAttribute(): ?int
    {
        if ($this->sale_price === null || $this->sale_price >= $this->price || $this->price === 0) {
            return null;
        }

        return (int) round((($this->price - $this->sale_price) / $this->price) * 100);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(ProductTag::class, 'product_product_tag');
    }

    public function labels(): BelongsToMany
    {
        return $this->belongsToMany(ProductLabel::class, 'product_product_label');
    }

    public function attributeValues(): BelongsToMany
    {
        return $this->belongsToMany(ProductAttributeValue::class, 'product_attribute_value_product');
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function faqs(): HasMany
    {
        return $this->hasMany(ProductFaq::class)->orderBy('sort_order');
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }
}
