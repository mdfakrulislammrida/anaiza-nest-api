<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SiteSetting extends Model
{
    protected $fillable = [
        'site_name',
        'logo_url',
        'contact_phone',
        'contact_email',
        'address',
        'facebook_url',
        'instagram_url',
        'youtube_url',
        'tiktok_url',
    ];
}
