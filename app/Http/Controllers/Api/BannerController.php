<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\BannerResource;
use App\Models\Banner;

class BannerController extends Controller
{
    public function index()
    {
        return BannerResource::collection(
            Banner::where('is_active', true)->orderBy('sort_order')->get()
        );
    }
}
