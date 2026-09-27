<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\HomepageSectionResource;
use App\Models\HomepageSection;

class HomepageSectionController extends Controller
{
    public function index()
    {
        return HomepageSectionResource::collection(
            HomepageSection::where('is_enabled', true)->orderBy('position')->get()
        );
    }
}
