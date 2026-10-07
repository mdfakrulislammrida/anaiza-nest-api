<?php

namespace App\Providers;

use App\Models\Category;
use App\Models\HomepageSection;
use App\Models\Order;
use App\Models\ProductImage;
use App\Observers\CategoryObserver;
use App\Observers\HomepageSectionObserver;
use App\Observers\OrderObserver;
use App\Observers\ProductImageObserver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Corporate enquiries carry no CAPTCHA, so they are limited per visitor instead (plus a honeypot).
        // A review needs an order to back it, but the number and phone are only five or six digits to guess, so attempts
        // are limited per address.
        RateLimiter::for('product-reviews', fn (Request $request) => Limit::perHour(10)->by($request->ip()));

        RateLimiter::for('corporate-enquiries', fn (Request $request) => Limit::perHour(5)->by($request->ip()));

        ProductImage::observe(ProductImageObserver::class);
        Category::observe(CategoryObserver::class);
        HomepageSection::observe(HomepageSectionObserver::class);
        Order::observe(OrderObserver::class);
    }
}
