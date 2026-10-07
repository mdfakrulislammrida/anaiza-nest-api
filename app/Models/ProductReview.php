<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A customer's review of a product they ordered. Reviews only come from the storefront (never from the admin), start
 * pending, and are public only once approved.
 */
class ProductReview extends Model
{
    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const STATUSES = [
        self::PENDING => 'Pending',
        self::APPROVED => 'Approved',
        self::REJECTED => 'Rejected',
    ];

    public const MAX_PHOTOS = 3;

    protected $fillable = [
        'product_id',
        'customer_id',
        'order_id',
        'order_item_id',
        'name',
        'rating',
        'title',
        'body',
        'photos',
        'status',
        'admin_reply',
        'admin_replied_at',
        'verified_purchase',
        'approved_at',
    ];

    protected $attributes = [
        'status' => self::PENDING,
        'verified_purchase' => true,
    ];

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'photos' => 'array',
            'verified_purchase' => 'boolean',
            'admin_replied_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', self::APPROVED);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    /**
     * Approve or reject. Only the admin's decision moves a review out of pending.
     */
    public function moderate(string $status): void
    {
        $this->forceFill([
            'status' => $status,
            'approved_at' => $status === self::APPROVED ? ($this->approved_at ?? now()) : null,
        ])->save();
    }
}
