<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentSettingResource;
use App\Models\PaymentSetting;

class PaymentSettingController extends Controller
{
    public function show()
    {
        // A GET is always a 200, even on the very first read that has to create the settings row.
        return PaymentSettingResource::make(PaymentSetting::query()->firstOrCreate([]))->response()->setStatusCode(200);
    }
}
