<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentSettingResource;
use App\Models\PaymentSetting;

class PaymentSettingController extends Controller
{
    public function show()
    {
        return PaymentSettingResource::make(PaymentSetting::query()->firstOrCreate([]));
    }
}
