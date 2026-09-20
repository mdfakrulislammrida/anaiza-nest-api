<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;

class HomeController extends Controller
{
    public function index()
    {
        $newArrivals = Product::query()
            ->where('is_active', true)
            ->where('is_new', true)
            ->with(['images' => fn ($q) => $q->orderBy('sort_order')])
            ->latest()
            ->take(8)
            ->get();

        $featured = Product::query()
            ->where('is_active', true)
            ->with(['images' => fn ($q) => $q->orderBy('sort_order')])
            ->latest()
            ->take(8)
            ->get();

        $categories = Category::withCount('products')->orderBy('name')->get();

        return view('storefront.home', compact('newArrivals', 'featured', 'categories'));
    }
}
