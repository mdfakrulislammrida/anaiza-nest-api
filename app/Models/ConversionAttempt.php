<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One try at reporting a Purchase to Meta or TikTok from the shop's own server (see App\Support\ConversionAttempts).
 */
class ConversionAttempt extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['order_id', 'platform', 'attempt', 'trigger', 'status', 'retryable', 'http_status', 'error', 'event_time', 'next_retry_at'];

    protected function casts(): array
    {
        return [
            'attempt' => 'integer',
            'retryable' => 'boolean',
            'http_status' => 'integer',
            'event_time' => 'integer',
            'next_retry_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
