<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Anaiza Nest — Premium Tea Sets & Gifts')</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        maroon: {
                            50: '#fdf3f3', 100: '#fbe4e4', 200: '#f5c8c8', 300: '#eb9d9d',
                            400: '#dc6a6a', 500: '#c74747', 600: '#7a2323', 700: '#5c1a1a',
                            800: '#4a1616', 900: '#3d1414',
                        },
                        cream: { 50: '#fefcf8', 100: '#fbf5e9', 200: '#f6ead0' },
                    },
                    fontFamily: {
                        serif: ['Georgia', 'Cambria', 'Times New Roman', 'serif'],
                    },
                },
            },
        };
    </script>
    <style>[x-cloak] { display: none !important; }</style>
</head>
<body class="bg-cream-50 text-maroon-900 min-h-screen flex flex-col">

    <header class="bg-maroon-700 text-cream-50 sticky top-0 z-40 shadow-md">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 py-4 flex items-center justify-between">
            <a href="{{ route('storefront.home') }}" class="font-serif text-2xl tracking-wide">
                Anaiza Nest
            </a>
            <nav class="hidden md:flex items-center gap-8 text-sm uppercase tracking-wider">
                <a href="{{ route('storefront.home') }}" class="hover:text-maroon-200">Home</a>
                <a href="{{ route('storefront.products.index') }}" class="hover:text-maroon-200">Shop</a>
            </nav>
            <a href="{{ route('storefront.cart.index') }}" class="relative inline-flex items-center gap-2 bg-maroon-800 hover:bg-maroon-900 px-4 py-2 rounded-full text-sm transition">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 3h1.386c.51 0 .955.343 1.087.835l.383 1.437M7.5 14.25a3 3 0 00-3 3h15.75m-12.75-3h11.218c1.121-2.3 1.87-4.798 2.182-7.401.108-.905-.608-1.684-1.52-1.684H5.106M7.5 14.25L5.106 5.25M7.5 14.25L5.25 12M8.25 18.75a.375.375 0 11-.75 0 .375.375 0 01.75 0zM19.5 18.75a.375.375 0 11-.75 0 .375.375 0 01.75 0z" />
                </svg>
                Cart
                @php $cartCount = collect(session('cart', []))->sum('quantity'); @endphp
                @if($cartCount > 0)
                    <span class="absolute -top-2 -right-2 bg-yellow-500 text-maroon-900 text-xs font-bold rounded-full w-5 h-5 flex items-center justify-center">
                        {{ $cartCount }}
                    </span>
                @endif
            </a>
        </div>
    </header>

    @if (session('success'))
        <div class="max-w-6xl mx-auto px-4 sm:px-6 mt-4">
            <div class="bg-green-100 border border-green-300 text-green-800 px-4 py-3 rounded-lg text-sm">
                {{ session('success') }}
            </div>
        </div>
    @endif

    @if ($errors->any())
        <div class="max-w-6xl mx-auto px-4 sm:px-6 mt-4">
            <div class="bg-red-100 border border-red-300 text-red-800 px-4 py-3 rounded-lg text-sm">
                <ul class="list-disc list-inside space-y-1">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
    @endif

    <main class="flex-1">
        @yield('content')
    </main>

    <footer class="bg-maroon-800 text-cream-100 mt-16">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 py-10 text-sm flex flex-col sm:flex-row justify-between gap-4">
            <div>
                <p class="font-serif text-lg mb-1">Anaiza Nest</p>
                <p class="text-cream-200">Premium tea sets &amp; gifts — Dhaka, Bangladesh</p>
            </div>
            <p class="text-cream-200">&copy; {{ date('Y') }} Anaiza Nest. All rights reserved.</p>
        </div>
    </footer>

</body>
</html>
