<?php

namespace App\Models;

use App\Http\Controllers\FeedController;
use App\Support\Pricing;
use App\Support\Sku;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

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
        'exclude_from_feeds',
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
            'exclude_from_feeds' => 'boolean',
        ];
    }

    /**
     * The price to actually charge and display: the sale price when one is
     * set (and lower than the regular price), otherwise the regular price.
     */
    public function getEffectivePriceAttribute(): int
    {
        return $this->on_sale ? $this->sale_price : $this->price;
    }

    /** A sale price of 0, a negative one, or one that is not lower than the regular price is no sale. */
    public function getOnSaleAttribute(): bool
    {
        return Pricing::saleApplies($this->sale_price, $this->price);
    }

    protected static function booted(): void
    {
        // The primary category always has a row in category_product. A new product gets its row; when the primary changes, the old primary's row moves to
        // the new one (a product that had one category still has one), and the extras are left alone. The admin form then sets the extras it was given.
        static::saved(function (self $product): void {
            // During "saved" the attribute is still dirty and the original still holds the old value (it is synced after the event). Not wasRecentlyCreated /
            // wasChanged: those stay true on the same instance for later saves.
            if (! $product->isDirty('category_id')) {
                return;
            }

            $old = $product->getOriginal('category_id');

            if ($old !== null && (int) $old !== (int) $product->category_id) {
                $product->categories()->detach((int) $old);
            }

            $product->categories()->syncWithoutDetaching([(int) $product->category_id]);

            FeedController::forgetAll();
        });

        // A sale price of 0 or less can only be a mistake (it once showed a 100% discount at ৳0): store it as no sale.
        static::saving(function (self $product): void {
            if ($product->sale_price !== null && $product->sale_price <= 0) {
                $product->sale_price = null;
            }

            // The SKU is the id the feeds and the pixels use: no stray "SKU " in front, no double spaces (App\Support\Sku).
            if ($product->sku !== null && $product->isDirty('sku')) {
                $product->sku = Sku::clean($product->sku);
            }
        });
    }

    public function getDiscountPercentAttribute(): ?int
    {
        if (! $this->on_sale) {
            return null;
        }

        return (int) round((($this->price - $this->sale_price) / $this->price) * 100);
    }

    /** The PRIMARY category (products.category_id): breadcrumb, feeds, schema, the category on a card. */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** The same relation under its clearer name. */
    public function primaryCategory(): BelongsTo
    {
        return $this->category();
    }

    /** Every category the product is listed in: the primary and the extras (category_product). The primary always has a row here. */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'category_product')->withPivot('sort_order')->withTimestamps()->orderByPivot('sort_order')->orderBy('categories.name');
    }

    /**
     * Products listed in a category, as primary or extra, each once (an EXISTS-style subquery adds no rows, so sorting, pagination and counts do not change).
     */
    public function scopeInCategory(Builder $query, int|Category $category): Builder
    {
        $id = $category instanceof Category ? $category->id : $category;

        return $query->whereIn($query->getModel()->qualifyColumn('id'), DB::table('category_product')->select('product_id')->where('category_id', $id));
    }

    /** The same by the category's address (slug). */
    public function scopeInCategorySlug(Builder $query, string $slug): Builder
    {
        return $query->whereIn($query->getModel()->qualifyColumn('id'), DB::table('category_product')->select('product_id')
            ->whereIn('category_id', DB::table('categories')->select('id')->where('slug', $slug)));
    }

    /** Lists the product in more categories. The primary is already there; nothing is removed. */
    public function addCategories(array $categoryIds): void
    {
        $this->categories()->syncWithoutDetaching($this->extrasOnly($categoryIds));
    }

    /** Takes the product out of the given categories. The primary is never removed. Returns how many were removed. */
    public function removeCategories(array $categoryIds): int
    {
        $ids = $this->extrasOnly($categoryIds);

        return $ids === [] ? 0 : $this->categories()->detach($ids);
    }

    /** @return list<int> the ids given, without the primary and without junk */
    private function extrasOnly(array $categoryIds): array
    {
        return collect($categoryIds)->map(fn ($id): int => (int) $id)->filter(fn (int $id): bool => $id > 0 && $id !== (int) $this->category_id)->unique()->values()->all();
    }

    /**
     * Makes the listed categories exactly the primary plus the extras given. The primary is always kept (and is never an "extra"); an extra that is the
     * primary is ignored. Nothing here changes products.category_id.
     *
     * @param  list<int|string>  $extraIds
     */
    public function syncCategories(array $extraIds = []): void
    {
        $primary = (int) $this->category_id;
        $ids = collect($extraIds)->map(fn ($id): int => (int) $id)->filter(fn (int $id): bool => $id > 0 && $id !== $primary)->unique()->values();

        $this->categories()->sync($ids->push($primary)->all());

        FeedController::forgetAll();
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

    public function reviews(): HasMany
    {
        return $this->hasMany(ProductReview::class);
    }

    /**
     * Adds rating_count and rating_average, from approved reviews only, to the products being loaded (one query for the
     * whole list, not one per product).
     */
    public function scopeWithRatings(Builder $query): Builder
    {
        return $query
            ->withCount(['reviews as rating_count' => fn ($reviews) => $reviews->where('status', ProductReview::APPROVED)])
            ->withAvg(['reviews as rating_average' => fn ($reviews) => $reviews->where('status', ProductReview::APPROVED)], 'rating');
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }
}
