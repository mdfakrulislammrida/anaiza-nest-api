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
            ->withRatings()
            ->with(['category', 'brand', 'images', 'tags', 'labels'])
            ->when($request->filled('category'), fn ($query) => $query->whereHas(
                'category',
                fn ($q) => $q->where('slug', $request->string('category'))
            ))
            ->when($request->filled('brand'), fn ($query) => $query->whereHas(
                'brand',
                fn ($q) => $q->where('slug', $request->string('brand'))
            ))
            ->when($request->filled('tag'), fn ($query) => $query->whereHas(
                'tags',
                fn ($q) => $q->where('slug', $request->string('tag'))
            ))
            ->when($request->filled('search'), fn ($query) => $query->where(
                'name',
                'like',
                '%'.$request->string('search').'%'
            ))
            ->when($request->boolean('is_new'), fn ($query) => $query->where('is_new', true))
            ->when($request->boolean('is_featured'), fn ($query) => $query->where('is_featured', true))
            ->when($request->boolean('on_sale'), fn ($query) => $query->whereNotNull('sale_price')
                ->whereColumn('sale_price', '<', 'price'))
            ->when($request->filled('min_price'), fn ($query) => $query->where('price', '>=', $request->integer('min_price')))
            ->when($request->filled('max_price'), fn ($query) => $query->where('price', '<=', $request->integer('max_price')))
            ->when($request->string('sort')->toString(), function ($query, $sort) {
                match ($sort) {
                    'newest' => $query->orderBy('created_at', 'desc'),
                    'price_asc' => $query->orderByRaw('COALESCE(sale_price, price) asc'),
                    'price_desc' => $query->orderByRaw('COALESCE(sale_price, price) desc'),
                    default => $query->orderBy('name'),
                };
            }, fn ($query) => $query->orderBy('name'))
            ->paginate($request->integer('per_page', 15));

        return ProductResource::collection($products);
    }

    public function show(Product $product)
    {
        abort_unless($product->is_active, 404);

        $product->load(['category', 'brand', 'images', 'variants.image', 'tags', 'labels', 'attributeValues.attribute', 'faqs']);
        $product->loadCount(['reviews as rating_count' => fn ($reviews) => $reviews->where('status', 'approved')]);
        $product->loadAvg(['reviews as rating_average' => fn ($reviews) => $reviews->where('status', 'approved')], 'rating');

        // Avoids an N+1: each variant's effective_price/effective_stock/etc.
        // accessor falls back to $this->product, so every variant needs it
        // set without triggering its own lazy-load query.
        $product->variants->each(fn ($variant) => $variant->setRelation('product', $product));

        return ProductResource::make($product);
    }
}
