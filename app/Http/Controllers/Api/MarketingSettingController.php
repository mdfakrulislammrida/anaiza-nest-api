<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MarketingSettingResource;
use App\Models\MarketingSetting;

class MarketingSettingController extends Controller
{
    public function show()
    {
        return MarketingSettingResource::make(MarketingSetting::query()->firstOrCreate([]));
    }
}
