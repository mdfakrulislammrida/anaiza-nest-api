<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SiteSettingResource;
use App\Models\SiteSetting;

class SiteSettingController extends Controller
{
    public function show()
    {
        // A GET is always a 200, even on the very first read that has to create the settings row.
        return SiteSettingResource::make(SiteSetting::query()->firstOrCreate([]))->response()->setStatusCode(200);
    }
}
