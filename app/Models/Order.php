<?php

namespace App\Models;

use App\Support\WalletPayments;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'shipping_zone_id',
        'status',
        'subtotal',
        'delivery_fee',
        'total',
        'payment_method',
        'gift_note',
        'is_gift',
        'gift_message',
        'marketing_consent',
        'client_ip',
        'client_user_agent',
        'payment_status',
        'payment_trx_id',
        'payment_sender_number',
        'payment_verified_at',
        'payment_verified_by',
        'payment_note',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_content',
        'utm_term',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'integer',
            'delivery_fee' => 'integer',
            'total' => 'integer',
            'is_gift' => 'boolean',
            'marketing_consent' => 'boolean',
            'payment_verified_at' => 'datetime',
            'conversion_sent_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Every order starts with a payment state that matches how it is being paid, including orders
        // typed in by hand in the admin. Only the verify / fail actions move it on from there.
        static::creating(function (Order $order): void {
            if (blank($order->payment_status) || $order->payment_status === 'cod' && $order->payment_method !== 'cod') {
                $order->payment_status = WalletPayments::initialStatus($order->payment_method);
            }
        });
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'payment_verified_by');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function shippingZone(): BelongsTo
    {
        return $this->belongsTo(ShippingZone::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }
}
