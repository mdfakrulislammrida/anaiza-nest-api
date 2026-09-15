<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PageResource;
use App\Models\Page;

class PageController extends Controller
{
    public function index()
    {
        return PageResource::collection(Page::orderBy('title')->get());
    }

    public function show(Page $page)
    {
        return PageResource::make($page);
    }
}
