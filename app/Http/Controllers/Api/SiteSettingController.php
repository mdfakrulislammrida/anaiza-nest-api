<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SiteSettingResource;
use App\Models\SiteSetting;

class SiteSettingController extends Controller
{
    public function show()
    {
        return SiteSettingResource::make(SiteSetting::query()->firstOrCreate([]));
    }
}
