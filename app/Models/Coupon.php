<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A discount code. It is only ever validated, and its discount computed, on the server (see App\Support\Coupons).
 *
 * valid_from and valid_until are exact moments (stored in UTC, entered and shown in Dhaka time). A coupon bound to a
 * person works only for an order from that email or that phone number; a single-use one stops working once an order
 * has used it. Only one coupon applies to an order.
 */
class Coupon extends Model
{
    use HasFactory;

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_RECOVERY = 'recovery';

    protected $fillable = [
        'code',
        'discount_type',
        'amount',
        'expires_at',
        'is_active',
        'valid_from',
        'valid_until',
        'min_order',
        'single_use',
        'bound_email',
        'bound_phone',
        'used_count',
        'used_at',
        'used_order_id',
        'source',
        'abandoned_checkout_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'expires_at' => 'date',
            'is_active' => 'boolean',
            'valid_from' => 'datetime',
            'valid_until' => 'datetime',
            'min_order' => 'integer',
            'single_use' => 'boolean',
            'used_count' => 'integer',
            'used_at' => 'datetime',
        ];
    }

    /**
     * A moment given in any time zone (a Dhaka time from the admin form, say) is stored as the same moment in UTC, so
     * the date is never read back as a different hour.
     */
    public function setValidFromAttribute($value): void
    {
        $this->attributes['valid_from'] = $value === null || $value === '' ? null : Carbon::parse($value)->utc()->format('Y-m-d H:i:s');
    }

    public function setValidUntilAttribute($value): void
    {
        $this->attributes['valid_until'] = $value === null || $value === '' ? null : Carbon::parse($value)->utc()->format('Y-m-d H:i:s');
    }

    public function isBound(): bool
    {
        return filled($this->bound_email) || filled($this->bound_phone);
    }

    public function usedOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'used_order_id');
    }

    /** The value as a customer reads it: "10%" or "৳200". */
    public function valueLabel(): string
    {
        return $this->discount_type === 'percent' ? $this->amount.'%' : '৳'.number_format($this->amount);
    }
}
