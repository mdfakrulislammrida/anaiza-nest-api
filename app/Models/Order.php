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
        'courier_name',
        'tracking_number',
        'emails_sent',
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
        'coupon_code',
        'coupon_id',
        'discount_amount',
        'delivered_at',
        'review_request_sent_at',
        'review_request_status',
        'review_reminder_sent_at',
        'review_whatsapp_clicked_at',
        'ship_name',
        'ship_email',
        'ship_phone',
        'ship_address',
        'ship_division',
        'ship_district',
        'ship_thana',
        'landing_page_id',
        'landing_slug',
        'fbclid',
        'gclid',
        'ttclid',
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
            'discount_amount' => 'integer',
            'delivered_at' => 'datetime',
            'review_request_sent_at' => 'datetime',
            'review_reminder_sent_at' => 'datetime',
            'review_whatsapp_clicked_at' => 'datetime',
            'emails_sent' => 'array',
            'courier_booked_at' => 'datetime',
            'courier_booking_started_at' => 'datetime',
            'courier_synced_at' => 'datetime',
            'courier_next_sync_at' => 'datetime',
            'courier_final_at' => 'datetime',
            'courier_returned_at' => 'datetime',
            'courier_last_error_at' => 'datetime',
            'cod_collected_at' => 'datetime',
            'cod_amount_sent' => 'integer',
            'cod_collected_amount' => 'integer',
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

    /**
     * Who the order is for and where it goes: the details typed at checkout and kept on the order, or (for an order made
     * before they were) the customer's. Everything that addresses a parcel, an invoice or an email reads this, never the
     * customer record, so a later order from the same phone can never change an earlier one.
     *
     * @return array{name: ?string, email: ?string, phone: ?string, address: ?string, division: ?string, district: ?string, thana: ?string}
     */
    public function recipient(): array
    {
        $customer = $this->customer;

        return [
            'name' => filled($this->ship_name) ? $this->ship_name : $customer?->name,
            'email' => filled($this->ship_email) ? $this->ship_email : $customer?->email,
            'phone' => filled($this->ship_phone) ? $this->ship_phone : $customer?->phone,
            'address' => filled($this->ship_address) ? $this->ship_address : $customer?->address,
            'division' => filled($this->ship_division) ? $this->ship_division : $customer?->division,
            'district' => filled($this->ship_district) ? $this->ship_district : $customer?->district,
            'thana' => filled($this->ship_thana) ? $this->ship_thana : $customer?->thana,
        ];
    }

    /** "House 1, Banani, Dhaka, Dhaka" */
    public function recipientAddressLine(): string
    {
        $to = $this->recipient();

        return implode(', ', array_filter([$to['address'], $to['thana'], $to['district'], $to['division']]));
    }

    /** Booked with a courier (its consignment id may be missing only after a lost answer from Steadfast). */
    public function isBooked(): bool
    {
        return $this->courier !== null && $this->courier_booked_at !== null;
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

    public function courierEvents(): HasMany
    {
        return $this->hasMany(CourierEvent::class, 'order_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }
}
