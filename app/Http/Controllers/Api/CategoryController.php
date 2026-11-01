<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryResource;
use App\Http\Resources\CategorySummaryResource;
use App\Models\Category;
use App\Support\Slugs;

class CategoryController extends Controller
{
    public function index()
    {
        return CategorySummaryResource::collection(Category::orderBy('menu_order')->orderBy('name')->get());
    }

    /** By the current address or one the category used to have (then the answer carries a `redirect` hint with the new path). */
    public function show(string $slug)
    {
        $found = Slugs::find('category', $slug);

        abort_if($found === null, 404);

        $category = $found['model']->load('faqs');

        return CategoryResource::make($category)->additional(['redirect' => Slugs::hint('category', $slug, $category)]);
    }
}
