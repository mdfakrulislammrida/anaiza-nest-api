@extends('layouts.storefront')

@section('title', $product->name.' — Anaiza Nest')

@section('content')
    <div class="max-w-6xl mx-auto px-4 sm:px-6 py-10">

        <nav class="text-sm text-maroon-500 mb-6">
            <a href="{{ route('storefront.home') }}" class="hover:underline">Home</a>
            <span class="mx-1">/</span>
            <a href="{{ route('storefront.products.index') }}" class="hover:underline">Shop</a>
            @if ($product->category)
                <span class="mx-1">/</span>
                <a href="{{ route('storefront.products.index', ['category' => $product->category->slug]) }}" class="hover:underline">{{ $product->category->name }}</a>
            @endif
        </nav>

        <div class="grid md:grid-cols-2 gap-10">
            {{-- Gallery --}}
            <div>
                @php $images = $product->images; @endphp
                <div class="aspect-square rounded-2xl overflow-hidden bg-cream-200">
                    @if ($images->isNotEmpty())
                        <img id="main-image" src="{{ $images->first()->display_url }}" alt="{{ $product->name }}" class="w-full h-full object-cover">
                    @else
                        <div class="w-full h-full flex items-center justify-center text-maroon-300">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-24 h-24" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 3c-2.5 3-4 5.5-4 8a4 4 0 108 0c0-2.5-1.5-5-4-8z" />
                            </svg>
                        </div>
                    @endif
                </div>
                @if ($images->count() > 1)
                    <div class="flex gap-3 mt-3">
                        @foreach ($images as $image)
                            <button type="button" onclick="document.getElementById('main-image').src = '{{ $image->display_url }}'"
                                    class="w-16 h-16 rounded-lg overflow-hidden bg-cream-200 border border-maroon-200 hover:border-maroon-500">
                                <img src="{{ $image->display_url }}" class="w-full h-full object-cover">
                            </button>
                        @endforeach
                    </div>
                @endif
            </div>

            {{-- Details --}}
            <div>
                @if ($product->is_new)
                    <span class="inline-block bg-maroon-700 text-cream-50 text-xs uppercase tracking-wider px-2 py-1 rounded-full mb-3">New</span>
                @endif
                <h1 class="font-serif text-3xl">{{ $product->name }}</h1>
                <p class="text-2xl text-maroon-700 font-semibold mt-3">৳{{ number_format($product->price) }}</p>

                @if ($product->description)
                    <p class="text-maroon-600 mt-5 leading-relaxed">{{ $product->description }}</p>
                @endif

                @if ($product->stock_quantity < 1)
                    <p class="mt-6 inline-block bg-maroon-100 text-maroon-700 px-4 py-2 rounded-lg text-sm">Currently sold out</p>
                @else
                    <form action="{{ route('storefront.cart.add') }}" method="POST" class="mt-8 space-y-6">
                        @csrf
                        <input type="hidden" name="product_id" value="{{ $product->id }}">

                        @if ($product->variants->isNotEmpty())
                            <div>
                                <p class="text-sm font-medium uppercase tracking-wide text-maroon-500 mb-2">Choose an option</p>
                                <div class="flex flex-wrap gap-2">
                                    @foreach ($product->variants as $variant)
                                        <label class="cursor-pointer">
                                            <input type="radio" name="variant_id" value="{{ $variant->id }}" class="sr-only peer" {{ $loop->first ? 'checked' : '' }}>
                                            <span class="block px-4 py-2 rounded-full border border-maroon-200 text-sm peer-checked:bg-maroon-700 peer-checked:text-cream-50 peer-checked:border-maroon-700 hover:border-maroon-500">
                                                {{ $variant->name }}: {{ $variant->value }}
                                            </span>
                                        </label>
                                    @endforeach
                                </div>
                            </div>
                        @endif

                        <div class="flex items-center gap-4">
                            <label for="quantity" class="text-sm font-medium uppercase tracking-wide text-maroon-500">Qty</label>
                            <input type="number" id="quantity" name="quantity" value="1" min="1" max="{{ $product->stock_quantity }}"
                                   class="w-20 border border-maroon-200 rounded-lg px-3 py-2 text-center">
                            <span class="text-sm text-maroon-400">{{ $product->stock_quantity }} in stock</span>
                        </div>

                        <button type="submit" class="w-full sm:w-auto bg-maroon-700 hover:bg-maroon-800 text-cream-50 px-8 py-3 rounded-full font-medium transition">
                            Add to Cart
                        </button>
                    </form>
                @endif

                <dl class="mt-8 pt-6 border-t border-maroon-100 text-sm text-maroon-500 space-y-1">
                    <div class="flex gap-2"><dt class="font-medium">SKU:</dt><dd>{{ $product->sku }}</dd></div>
                    @if ($product->category)
                        <div class="flex gap-2"><dt class="font-medium">Category:</dt><dd>{{ $product->category->name }}</dd></div>
                    @endif
                </dl>
            </div>
        </div>

        @if ($related->isNotEmpty())
            <section class="mt-16">
                <h2 class="font-serif text-2xl mb-6">You may also like</h2>
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-6 sm:gap-8">
                    @foreach ($related as $item)
                        @include('storefront.partials.product-card', ['product' => $item])
                    @endforeach
                </div>
            </section>
        @endif
    </div>
@endsection
