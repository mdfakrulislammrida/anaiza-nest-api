<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MarketingSettingResource;
use App\Models\MarketingSetting;

class MarketingSettingController extends Controller
{
    public function show()
    {
        // A GET is always a 200, even on the very first read that has to create the settings row.
        return MarketingSettingResource::make(MarketingSetting::query()->firstOrCreate([]))->response()->setStatusCode(200);
    }
}
