@extends('layouts.storefront')

@section('title', 'Anaiza Nest — Premium Tea Sets & Gifts')

@section('content')

    <section class="bg-maroon-700 text-cream-50">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 py-20 text-center">
            <h1 class="font-serif text-4xl sm:text-5xl leading-tight">Pour Something Beautiful</h1>
            <p class="mt-4 text-cream-200 max-w-xl mx-auto">
                Hand-picked porcelain tea sets and gift pieces, delivered across Dhaka and beyond.
            </p>
            <a href="{{ route('storefront.products.index') }}" class="inline-block mt-8 bg-cream-50 text-maroon-800 px-8 py-3 rounded-full font-medium hover:bg-cream-100 transition">
                Shop the Collection
            </a>
        </div>
    </section>

    @if ($categories->isNotEmpty())
        <section class="max-w-6xl mx-auto px-4 sm:px-6 py-12">
            <h2 class="font-serif text-2xl mb-6">Shop by Category</h2>
            <div class="flex flex-wrap gap-3">
                @foreach ($categories as $category)
                    <a href="{{ route('storefront.products.index', ['category' => $category->slug]) }}"
                       class="px-5 py-2 rounded-full border border-maroon-300 hover:bg-maroon-700 hover:text-cream-50 hover:border-maroon-700 transition text-sm">
                        {{ $category->name }} <span class="text-maroon-400">({{ $category->products_count }})</span>
                    </a>
                @endforeach
            </div>
        </section>
    @endif

    @if ($newArrivals->isNotEmpty())
        <section class="max-w-6xl mx-auto px-4 sm:px-6 py-8">
            <h2 class="font-serif text-2xl mb-6">New Arrivals</h2>
            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-6 sm:gap-8">
                @foreach ($newArrivals as $product)
                    @include('storefront.partials.product-card', ['product' => $product])
                @endforeach
            </div>
        </section>
    @endif

    <section class="max-w-6xl mx-auto px-4 sm:px-6 py-8">
        <h2 class="font-serif text-2xl mb-6">All Products</h2>
        @if ($featured->isEmpty())
            <p class="text-maroon-500">No products yet — add some from the admin dashboard.</p>
        @else
            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-6 sm:gap-8">
                @foreach ($featured as $product)
                    @include('storefront.partials.product-card', ['product' => $product])
                @endforeach
            </div>
        @endif
    </section>

@endsection
