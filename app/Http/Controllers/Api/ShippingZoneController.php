<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ShippingZoneResource;
use App\Models\ShippingZone;

class ShippingZoneController extends Controller
{
    public function index()
    {
        return ShippingZoneResource::collection(ShippingZone::orderBy('name')->get());
    }
}
