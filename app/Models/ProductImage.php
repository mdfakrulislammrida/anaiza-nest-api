<?php

namespace App\Models;

use App\Support\MediaUrl;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductImage extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'url',
        'width',
        'height',
        'url_400',
        'url_800',
        'alt_text',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function getDisplayUrlAttribute(): ?string
    {
        return MediaUrl::resolve($this->url);
    }

    public function getDisplayUrl400Attribute(): ?string
    {
        return MediaUrl::resolve($this->url_400);
    }

    public function getDisplayUrl800Attribute(): ?string
    {
        return MediaUrl::resolve($this->url_800);
    }
}
