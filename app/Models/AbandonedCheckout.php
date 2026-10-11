<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A cart or checkout left by a visitor we can contact (a valid email or phone number). Visitors with neither are never
 * stored, only counted (see CheckoutDailyCount).
 */
class AbandonedCheckout extends Model
{
    public const STAGES = [
        'cart' => 'Cart',
        'checkout_started' => 'Checkout started',
        'contact_filled' => 'Contact filled',
        'ordered' => 'Ordered',
    ];

    public const STATUS_OPEN = 'open';

    public const STATUS_RECOVERED = 'recovered';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_UNSUBSCRIBED = 'unsubscribed';

    public const STATUS_IGNORED = 'ignored';

    /** Ordered on their own, with no reminder from us: not abandoned, not recovered. */
    public const STATUS_ORDERED = 'ordered';

    public const STATUSES = [
        self::STATUS_OPEN => 'Open',
        self::STATUS_RECOVERED => 'Recovered',
        self::STATUS_EXPIRED => 'Expired',
        self::STATUS_UNSUBSCRIBED => 'Unsubscribed',
        self::STATUS_IGNORED => 'Ignored',
        self::STATUS_ORDERED => 'Ordered',
    ];

    protected $fillable = [
        'session_token',
        'recovery_token',
        'customer_id',
        'name',
        'email',
        'phone',
        'stage',
        'items',
        'cart_total',
        'viewed_products',
        'first_activity_at',
        'last_activity_at',
        'order_id',
        'ordered_at',
        'marketing_consent',
        'whatsapp_optin',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_content',
        'utm_term',
        'status',
        'messages',
        'messages_count',
        'last_message_at',
        'coupon_id',
        'unsubscribed',
        'whatsapp_clicked_at',
    ];

    protected $attributes = [
        'stage' => 'cart',
        'status' => self::STATUS_OPEN,
        'cart_total' => 0,
        'messages_count' => 0,
    ];

    protected function casts(): array
    {
        return [
            'items' => 'array',
            'viewed_products' => 'array',
            'messages' => 'array',
            'cart_total' => 'integer',
            'messages_count' => 'integer',
            'marketing_consent' => 'boolean',
            'whatsapp_optin' => 'boolean',
            'unsubscribed' => 'boolean',
            'first_activity_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'ordered_at' => 'datetime',
            'last_message_at' => 'datetime',
            'whatsapp_clicked_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (AbandonedCheckout $checkout): void {
            $checkout->recovery_token ??= Str::random(40);
        });
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    /** Whether step $step (1 or 2) has already gone out. */
    public function sentStep(int $step): bool
    {
        return isset(($this->messages ?? [])["step{$step}"]);
    }

    /** "Priya", or the best we have to call them by. */
    public function displayName(): string
    {
        return filled($this->name) ? trim($this->name) : (filled($this->email) ? $this->email : (string) $this->phone);
    }
}
