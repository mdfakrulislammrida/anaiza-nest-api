<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\BannerResource;
use App\Models\Banner;
use App\Models\SiteSetting;

class BannerController extends Controller
{
    /**
     * The slides showing now (on, started, not ended), in order, five at most, with the slider's settings.
     */
    public function index()
    {
        $site = SiteSetting::query()->first();

        return BannerResource::collection(
            Banner::query()->live()->orderBy('sort_order')->orderBy('id')->limit(Banner::MAX_SLIDES)->get()
        )->additional([
            'settings' => [
                'autoplay' => (bool) ($site?->slider_autoplay ?? false),
                'interval_seconds' => SiteSetting::sliderInterval($site),
            ],
        ]);
    }
}
