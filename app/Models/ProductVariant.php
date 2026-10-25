<?php

namespace App\Models;

use App\Support\Pricing;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductVariant extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'name',
        'value',
        'price',
        'sale_price',
        'stock_quantity',
        'sku',
        'image_id',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'sale_price' => 'integer',
            'stock_quantity' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function image(): BelongsTo
    {
        return $this->belongsTo(ProductImage::class, 'image_id');
    }

    /**
     * The price to actually charge when this variant is selected: its own
     * sale/regular price when set, otherwise the parent product's (already
     * sale-aware) effective price -- mirrors Product::getEffectivePriceAttribute().
     */
    public function getEffectivePriceAttribute(): int
    {
        if ($this->price === null) {
            return $this->product->effective_price;
        }

        return $this->on_sale ? $this->sale_price : $this->price;
    }

    /** With its own regular price: a sale price more than 0 and lower than it. Without one: the product's own sale. */
    public function getOnSaleAttribute(): bool
    {
        if ($this->price === null) {
            return $this->product->on_sale;
        }

        return Pricing::saleApplies($this->sale_price, $this->price);
    }

    protected static function booted(): void
    {
        static::saving(function (self $variant): void {
            if ($variant->sale_price !== null && $variant->sale_price <= 0) {
                $variant->sale_price = null;
            }
        });
    }

    public function getDiscountPercentAttribute(): ?int
    {
        if ($this->price === null) {
            return $this->product->discount_percent;
        }

        if (! $this->on_sale) {
            return null;
        }

        return (int) round((($this->price - $this->sale_price) / $this->price) * 100);
    }

    /**
     * How many of this specific variant can be bought: its own stock count
     * when tracked separately, otherwise the parent product's shared pool.
     */
    public function getEffectiveStockAttribute(): int
    {
        return $this->stock_quantity ?? $this->product->stock_quantity;
    }

    public function getEffectiveSkuAttribute(): string
    {
        return $this->sku ?? $this->product->sku;
    }
}
