@extends('layouts.storefront')

@section('title', 'Order Confirmed — Anaiza Nest')

@section('content')
    <div class="max-w-2xl mx-auto px-4 sm:px-6 py-16 text-center">
        <div class="w-16 h-16 rounded-full bg-green-100 text-green-600 flex items-center justify-center mx-auto mb-6">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
            </svg>
        </div>
        <h1 class="font-serif text-3xl mb-2">Thank you, {{ $order->customer->name }}!</h1>
        <p class="text-maroon-500 mb-8">Your order #{{ $order->id }} has been placed and is pending confirmation.</p>

        <div class="bg-white border border-maroon-100 rounded-xl p-6 text-left">
            <h2 class="font-serif text-xl mb-4">Order Summary</h2>
            <div class="space-y-3">
                @foreach ($order->items as $item)
                    <div class="flex justify-between text-sm">
                        <div>
                            <p class="font-medium">{{ $item->product_name }} &times; {{ $item->quantity }}</p>
                            @if ($item->variant_name)
                                <p class="text-maroon-400">{{ $item->variant_name }}: {{ $item->variant_value }}</p>
                            @endif
                        </div>
                        <p class="font-medium">৳{{ number_format($item->price * $item->quantity) }}</p>
                    </div>
                @endforeach
            </div>
            <div class="border-t border-maroon-100 mt-4 pt-4 space-y-1 text-sm">
                <div class="flex justify-between"><span>Subtotal</span><span>৳{{ number_format($order->subtotal) }}</span></div>
                <div class="flex justify-between"><span>Delivery</span><span>৳{{ number_format($order->delivery_fee) }}</span></div>
                <div class="flex justify-between font-semibold text-lg mt-2"><span>Total</span><span>৳{{ number_format($order->total) }}</span></div>
            </div>
            <div class="border-t border-maroon-100 mt-4 pt-4 text-sm text-maroon-500 space-y-1">
                <p><span class="font-medium text-maroon-700">Payment:</span> {{ strtoupper($order->payment_method) }}</p>
                <p><span class="font-medium text-maroon-700">Delivering to:</span> {{ $order->customer->address }}, {{ $order->customer->city }} {{ $order->customer->postal_code }}</p>
                @if ($order->gift_note)
                    <p><span class="font-medium text-maroon-700">Gift note:</span> {{ $order->gift_note }}</p>
                @endif
            </div>
        </div>

        <a href="{{ route('storefront.products.index') }}" class="inline-block mt-8 bg-maroon-700 text-cream-50 px-8 py-3 rounded-full hover:bg-maroon-800">
            Continue Shopping
        </a>
    </div>
@endsection
