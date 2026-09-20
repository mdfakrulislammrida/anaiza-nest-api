@extends('layouts.storefront')

@section('title', 'Checkout — Anaiza Nest')

@section('content')
    <div class="max-w-5xl mx-auto px-4 sm:px-6 py-10">
        <h1 class="font-serif text-3xl mb-8">Checkout</h1>

        <div class="grid md:grid-cols-5 gap-10">
            {{-- Form --}}
            <form action="{{ route('storefront.checkout.store') }}" method="POST" class="md:col-span-3 space-y-6">
                @csrf

                <div>
                    <h2 class="font-serif text-xl mb-4">Delivery Details</h2>
                    <div class="grid sm:grid-cols-2 gap-4">
                        <div class="sm:col-span-2">
                            <label class="text-sm font-medium text-maroon-600">Full Name</label>
                            <input type="text" name="customer_name" value="{{ old('customer_name') }}" required
                                   class="mt-1 w-full border border-maroon-200 rounded-lg px-3 py-2">
                        </div>
                        <div>
                            <label class="text-sm font-medium text-maroon-600">Email</label>
                            <input type="email" name="customer_email" value="{{ old('customer_email') }}" required
                                   class="mt-1 w-full border border-maroon-200 rounded-lg px-3 py-2">
                        </div>
                        <div>
                            <label class="text-sm font-medium text-maroon-600">Phone</label>
                            <input type="text" name="customer_phone" value="{{ old('customer_phone') }}" required
                                   class="mt-1 w-full border border-maroon-200 rounded-lg px-3 py-2">
                        </div>
                        <div class="sm:col-span-2">
                            <label class="text-sm font-medium text-maroon-600">Address</label>
                            <input type="text" name="customer_address" value="{{ old('customer_address') }}" required
                                   class="mt-1 w-full border border-maroon-200 rounded-lg px-3 py-2">
                        </div>
                        <div>
                            <label class="text-sm font-medium text-maroon-600">City</label>
                            <input type="text" name="customer_city" value="{{ old('customer_city') }}" required placeholder="e.g. Dhaka"
                                   class="mt-1 w-full border border-maroon-200 rounded-lg px-3 py-2">
                        </div>
                        <div>
                            <label class="text-sm font-medium text-maroon-600">Postal Code</label>
                            <input type="text" name="customer_postal_code" value="{{ old('customer_postal_code') }}" required
                                   class="mt-1 w-full border border-maroon-200 rounded-lg px-3 py-2">
                        </div>
                    </div>
                    <p class="text-xs text-maroon-400 mt-2">Delivery is ৳60 inside Dhaka, ৳120 outside Dhaka.</p>
                </div>

                <div>
                    <h2 class="font-serif text-xl mb-4">Payment Method</h2>
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                        @foreach (['cod' => 'Cash on Delivery', 'bkash' => 'bKash', 'nagad' => 'Nagad', 'card' => 'Card'] as $value => $label)
                            <label class="cursor-pointer">
                                <input type="radio" name="payment_method" value="{{ $value }}" class="sr-only peer" {{ old('payment_method', 'cod') === $value ? 'checked' : '' }}>
                                <span class="block text-center px-3 py-3 rounded-lg border border-maroon-200 text-sm peer-checked:bg-maroon-700 peer-checked:text-cream-50 peer-checked:border-maroon-700 hover:border-maroon-500">
                                    {{ $label }}
                                </span>
                            </label>
                        @endforeach
                    </div>
                </div>

                <div>
                    <label class="text-sm font-medium text-maroon-600">Gift Note (optional)</label>
                    <textarea name="gift_note" rows="3" placeholder="Add a personal message for the recipient..."
                              class="mt-1 w-full border border-maroon-200 rounded-lg px-3 py-2">{{ old('gift_note') }}</textarea>
                </div>

                <button type="submit" class="w-full bg-maroon-700 hover:bg-maroon-800 text-cream-50 px-8 py-4 rounded-full font-medium transition">
                    Place Order
                </button>
            </form>

            {{-- Summary --}}
            <div class="md:col-span-2">
                <div class="bg-white border border-maroon-100 rounded-xl p-6 sticky top-24">
                    <h2 class="font-serif text-xl mb-4">Order Summary</h2>
                    <div class="space-y-3 max-h-72 overflow-y-auto pr-1">
                        @foreach ($lines as $line)
                            <div class="flex justify-between text-sm">
                                <div class="pr-2">
                                    <p class="font-medium">{{ $line['product']->name }} &times; {{ $line['quantity'] }}</p>
                                    @if ($line['variant'])
                                        <p class="text-maroon-400">{{ $line['variant']->name }}: {{ $line['variant']->value }}</p>
                                    @endif
                                </div>
                                <p class="shrink-0 font-medium">৳{{ number_format($line['line_total']) }}</p>
                            </div>
                        @endforeach
                    </div>
                    <div class="border-t border-maroon-100 mt-4 pt-4 flex justify-between font-semibold text-lg">
                        <span>Subtotal</span>
                        <span>৳{{ number_format($subtotal) }}</span>
                    </div>
                    <p class="text-xs text-maroon-400 mt-1">+ delivery fee, calculated at checkout</p>
                </div>
            </div>
        </div>
    </div>
@endsection
