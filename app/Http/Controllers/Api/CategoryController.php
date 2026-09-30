<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryResource;
use App\Http\Resources\CategorySummaryResource;
use App\Models\Category;

class CategoryController extends Controller
{
    public function index()
    {
        return CategorySummaryResource::collection(Category::orderBy('name')->get());
    }

    public function show(Category $category)
    {
        $category->load('faqs');

        return CategoryResource::make($category);
    }
}
