<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PopupSettingResource;
use App\Models\PopupSetting;

class PopupSettingController extends Controller
{
    public function show()
    {
        // A GET is always a 200, even on the very first read that has to create the settings row.
        return PopupSettingResource::make(PopupSetting::query()->firstOrCreate([]))->response()->setStatusCode(200);
    }
}
