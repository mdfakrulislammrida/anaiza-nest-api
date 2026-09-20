@extends('layouts.storefront')

@section('title', 'Shop — Anaiza Nest')

@section('content')
    <div class="max-w-6xl mx-auto px-4 sm:px-6 py-10">

        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-8">
            <h1 class="font-serif text-3xl">Shop</h1>
            <form method="GET" class="flex gap-2">
                @if (request('category'))
                    <input type="hidden" name="category" value="{{ request('category') }}">
                @endif
                <input type="text" name="search" value="{{ request('search') }}" placeholder="Search products..."
                       class="border border-maroon-200 rounded-full px-4 py-2 text-sm w-56 focus:outline-none focus:ring-2 focus:ring-maroon-400">
                <button class="bg-maroon-700 text-cream-50 px-5 py-2 rounded-full text-sm hover:bg-maroon-800">Search</button>
            </form>
        </div>

        <div class="flex flex-wrap gap-2 mb-8">
            <a href="{{ route('storefront.products.index') }}"
               class="px-4 py-1.5 rounded-full text-sm border {{ request('category') ? 'border-maroon-200 hover:bg-maroon-50' : 'bg-maroon-700 text-cream-50 border-maroon-700' }}">
                All
            </a>
            @foreach ($categories as $category)
                <a href="{{ route('storefront.products.index', ['category' => $category->slug]) }}"
                   class="px-4 py-1.5 rounded-full text-sm border {{ request('category') === $category->slug ? 'bg-maroon-700 text-cream-50 border-maroon-700' : 'border-maroon-200 hover:bg-maroon-50' }}">
                    {{ $category->name }}
                </a>
            @endforeach
        </div>

        @if ($products->isEmpty())
            <p class="text-maroon-500">No products found.</p>
        @else
            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-6 sm:gap-8">
                @foreach ($products as $product)
                    @include('storefront.partials.product-card', ['product' => $product])
                @endforeach
            </div>

            <div class="mt-10">
                {{ $products->links() }}
            </div>
        @endif
    </div>
@endsection
