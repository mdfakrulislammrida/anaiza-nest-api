<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PageResource;
use App\Models\Page;
use App\Support\Slugs;

class PageController extends Controller
{
    public function index()
    {
        return PageResource::collection(Page::orderBy('title')->get());
    }

    /** By the current address or one the page used to have (then the answer carries a `redirect` hint with the new path). */
    public function show(string $slug)
    {
        $found = Slugs::find('page', $slug);

        abort_if($found === null, 404);

        return PageResource::make($found['model'])->additional(['redirect' => Slugs::hint('page', $slug, $found['model'])]);
    }
}
