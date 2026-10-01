<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PopupSettingResource;
use App\Models\PopupSetting;

class PopupSettingController extends Controller
{
    public function show()
    {
        return PopupSettingResource::make(PopupSetting::query()->firstOrCreate([]));
    }
}
