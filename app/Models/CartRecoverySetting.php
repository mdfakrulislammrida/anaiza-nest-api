<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * "Cart recovery": up to two reminder emails for a checkout that was left, with the pacing and limits around them.
 */
class CartRecoverySetting extends Model
{
    public const DEFAULT_WHATSAPP_MESSAGE = 'Hello {name}, the pieces you chose are still waiting for you. Your cart is saved: {link}';

    protected $fillable = [
        'enabled',
        'enabled_at',
        'step1_enabled',
        'step1_delay_minutes',
        'step2_enabled',
        'step2_delay_minutes',
        'step2_coupon_enabled',
        'coupon_type',
        'coupon_amount',
        'coupon_min_order',
        'coupon_valid_hours',
        'max_messages',
        'quiet_start',
        'quiet_end',
        'daily_cap',
        'min_cart_value',
        'max_age_days',
        'retention_days',
        'whatsapp_message',
    ];

    protected $attributes = [
        'enabled' => false,
        'step1_enabled' => true,
        'step1_delay_minutes' => 60,
        'step2_enabled' => true,
        'step2_delay_minutes' => 2880,
        'step2_coupon_enabled' => false,
        'coupon_type' => 'percent',
        'coupon_amount' => 10,
        'coupon_valid_hours' => 48,
        'max_messages' => 2,
        'quiet_start' => '22:00',
        'quiet_end' => '08:00',
        'daily_cap' => 100,
        'min_cart_value' => 0,
        'max_age_days' => 7,
        'retention_days' => 90,
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'enabled_at' => 'datetime',
            'step1_enabled' => 'boolean',
            'step1_delay_minutes' => 'integer',
            'step2_enabled' => 'boolean',
            'step2_delay_minutes' => 'integer',
            'step2_coupon_enabled' => 'boolean',
            'coupon_amount' => 'integer',
            'coupon_min_order' => 'integer',
            'coupon_valid_hours' => 'integer',
            'max_messages' => 'integer',
            'daily_cap' => 'integer',
            'min_cart_value' => 'integer',
            'max_age_days' => 'integer',
            'retention_days' => 'integer',
        ];
    }

    public static function current(): self
    {
        return static::query()->first() ?? new static;
    }

    public function whatsappMessage(): string
    {
        return filled($this->whatsapp_message) ? trim($this->whatsapp_message) : self::DEFAULT_WHATSAPP_MESSAGE;
    }
}
