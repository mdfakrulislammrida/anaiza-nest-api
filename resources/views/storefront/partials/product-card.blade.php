@php $image = $product->images->first(); @endphp
<a href="{{ route('storefront.products.show', $product->slug) }}" class="group block">
    <div class="aspect-square w-full rounded-xl overflow-hidden bg-cream-200 relative">
        @if ($image)
            <img src="{{ $image->display_url }}" alt="{{ $product->name }}" class="w-full h-full object-cover group-hover:scale-105 transition duration-300" loading="lazy">
        @else
            <div class="w-full h-full flex items-center justify-center text-maroon-300">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-16 h-16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 3c-2.5 3-4 5.5-4 8a4 4 0 108 0c0-2.5-1.5-5-4-8z" />
                </svg>
            </div>
        @endif
        @if ($product->is_new)
            <span class="absolute top-3 left-3 bg-maroon-700 text-cream-50 text-xs uppercase tracking-wider px-2 py-1 rounded-full">New</span>
        @endif
        @if ($product->stock_quantity < 1)
            <span class="absolute inset-0 bg-maroon-900/60 flex items-center justify-center text-cream-50 text-sm uppercase tracking-widest">Sold Out</span>
        @endif
    </div>
    <div class="mt-3">
        <p class="font-serif text-lg leading-snug group-hover:text-maroon-600">{{ $product->name }}</p>
        <p class="text-maroon-700 font-semibold mt-1">৳{{ number_format($product->price) }}</p>
    </div>
</a>
