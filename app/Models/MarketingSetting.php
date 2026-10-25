<?php

namespace App\Models;

use App\Models\Concerns\HasSecretHints;
use Illuminate\Database\Eloquent\Model;

class MarketingSetting extends Model
{
    use HasSecretHints;

    /** The fields that hold a secret: encrypted at rest, blank in the admin form, kept when left blank on save. */
    public const SECRET_FIELDS = ['meta_capi_access_token', 'tiktok_events_api_access_token'];

    protected $fillable = [
        'gtm_container_id',
        'meta_pixel_id',
        'meta_capi_access_token',
        'ga4_id',
        'tiktok_pixel_id',
        'tiktok_events_api_access_token',
        'meta_test_event_code',
        'tiktok_test_event_code',
    ];

    /** Never serialised with its secrets, whatever calls toArray() or toJson() on it (the admin form is filled from toArray()). */
    protected $hidden = self::SECRET_FIELDS;

    protected function casts(): array
    {
        return [
            'meta_capi_access_token' => 'encrypted',
            'tiktok_events_api_access_token' => 'encrypted',
        ];
    }
}
