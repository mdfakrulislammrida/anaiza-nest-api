<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $products = Product::query()
            ->where('is_active', true)
            ->with(['category', 'images'])
            ->when($request->filled('category'), fn ($query) => $query->whereHas(
                'category',
                fn ($q) => $q->where('slug', $request->string('category'))
            ))
            ->when($request->filled('search'), fn ($query) => $query->where(
                'name',
                'like',
                '%'.$request->string('search').'%'
            ))
            ->when($request->boolean('is_new'), fn ($query) => $query->where('is_new', true))
            ->orderBy('name')
            ->paginate($request->integer('per_page', 15));

        return ProductResource::collection($products);
    }

    public function show(Product $product)
    {
        abort_unless($product->is_active, 404);

        $product->load(['category', 'images', 'variants']);

        return ProductResource::make($product);
    }
}
