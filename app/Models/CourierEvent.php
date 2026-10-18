<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * What happened to a parcel, one row each: booked, a status reported by polling or a webhook, a failure, an event we only
 * keep. Append-only: nothing in the app edits or deletes a row. The payload never holds a key, a token or a phone number.
 */
class CourierEvent extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
