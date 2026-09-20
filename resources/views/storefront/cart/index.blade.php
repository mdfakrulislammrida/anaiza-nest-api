@extends('layouts.storefront')

@section('title', 'Your Cart — Anaiza Nest')

@section('content')
    <div class="max-w-4xl mx-auto px-4 sm:px-6 py-10">
        <h1 class="font-serif text-3xl mb-8">Your Cart</h1>

        @if (empty($lines))
            <div class="text-center py-16">
                <p class="text-maroon-500 mb-6">Your cart is empty.</p>
                <a href="{{ route('storefront.products.index') }}" class="inline-block bg-maroon-700 text-cream-50 px-8 py-3 rounded-full hover:bg-maroon-800">
                    Continue Shopping
                </a>
            </div>
        @else
            <div class="space-y-4">
                @foreach ($lines as $line)
                    <div class="flex items-center gap-4 bg-white border border-maroon-100 rounded-xl p-4">
                        @php $image = $line['product']->images->first(); @endphp
                        <div class="w-20 h-20 rounded-lg overflow-hidden bg-cream-200 shrink-0">
                            @if ($image)
                                <img src="{{ $image->display_url }}" class="w-full h-full object-cover">
                            @endif
                        </div>

                        <div class="flex-1 min-w-0">
                            <a href="{{ route('storefront.products.show', $line['product']->slug) }}" class="font-serif text-lg hover:text-maroon-600 truncate block">
                                {{ $line['product']->name }}
                            </a>
                            @if ($line['variant'])
                                <p class="text-sm text-maroon-400">{{ $line['variant']->name }}: {{ $line['variant']->value }}</p>
                            @endif
                            <p class="text-maroon-700 font-semibold mt-1">৳{{ number_format($line['product']->price) }}</p>
                        </div>

                        <form action="{{ route('storefront.cart.update', $line['key']) }}" method="POST" class="flex items-center gap-2">
                            @csrf
                            @method('PATCH')
                            <input type="number" name="quantity" value="{{ $line['quantity'] }}" min="1" max="{{ $line['product']->stock_quantity }}"
                                   class="w-16 border border-maroon-200 rounded-lg px-2 py-1 text-center" onchange="this.form.submit()">
                        </form>

                        <p class="w-24 text-right font-semibold text-maroon-800">৳{{ number_format($line['line_total']) }}</p>

                        <form action="{{ route('storefront.cart.remove', $line['key']) }}" method="POST">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="text-maroon-400 hover:text-maroon-700" title="Remove">
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </form>
                    </div>
                @endforeach
            </div>

            <div class="mt-8 flex flex-col items-end gap-4">
                <p class="text-xl font-serif">Subtotal: <span class="font-semibold">৳{{ number_format($subtotal) }}</span></p>
                <div class="flex gap-3">
                    <a href="{{ route('storefront.products.index') }}" class="px-6 py-3 rounded-full border border-maroon-300 hover:bg-maroon-50">
                        Continue Shopping
                    </a>
                    <a href="{{ route('storefront.checkout.index') }}" class="px-8 py-3 rounded-full bg-maroon-700 text-cream-50 hover:bg-maroon-800">
                        Checkout
                    </a>
                </div>
            </div>
        @endif
    </div>
@endsection
