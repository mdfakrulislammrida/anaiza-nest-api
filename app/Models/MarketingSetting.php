<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketingSetting extends Model
{
    protected $fillable = [
        'gtm_container_id',
        'meta_pixel_id',
        'meta_capi_access_token',
        'ga4_id',
        'tiktok_pixel_id',
        'tiktok_events_api_access_token',
    ];

    protected function casts(): array
    {
        return [
            'meta_capi_access_token' => 'encrypted',
            'tiktok_events_api_access_token' => 'encrypted',
        ];
    }
}
