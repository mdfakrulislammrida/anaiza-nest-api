<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * "Review requests": whether and when customers are asked to review what they received. Off until the admin switches
 * it on; only orders delivered after that moment are asked.
 */
class ReviewRequestSetting extends Model
{
    public const DEFAULT_WHATSAPP_MESSAGE = 'Hello {name}, we hope your order #{order} has found its place. If you have a minute, a few words about it would mean a lot: {link}';

    protected $fillable = [
        'enabled',
        'enabled_at',
        'days_after_delivery',
        'email_enabled',
        'whatsapp_enabled',
        'reminder_enabled',
        'reminder_days',
        'whatsapp_message',
    ];

    protected $attributes = [
        'enabled' => false,
        'days_after_delivery' => 5,
        'email_enabled' => true,
        'whatsapp_enabled' => true,
        'reminder_enabled' => false,
        'reminder_days' => 7,
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'enabled_at' => 'datetime',
            'days_after_delivery' => 'integer',
            'email_enabled' => 'boolean',
            'whatsapp_enabled' => 'boolean',
            'reminder_enabled' => 'boolean',
            'reminder_days' => 'integer',
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
