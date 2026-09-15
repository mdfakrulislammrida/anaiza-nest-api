<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketingSetting extends Model
{
    protected $fillable = [
        'gtm_container_id',
        'meta_pixel_id',
        'ga4_id',
        'tiktok_pixel_id',
    ];
}
