<?php

namespace App\Providers;

use App\Models\Banner;
use App\Models\Category;
use App\Models\HomepageSection;
use App\Models\Order;
use App\Models\ProductImage;
use App\Observers\BannerObserver;
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

        // Asking for a reset link, or using one: by address, so guessing at links or at accounts is slow.
        RateLimiter::for('password-reset', fn (Request $request) => Limit::perHour(20)->by($request->ip()));

        // Logging in by phone number costs a little more to look up than by email, and a password can be guessed: attempts
        // are limited per address and per account name typed.
        RateLimiter::for('customer-login', fn (Request $request) => [
            Limit::perMinute(10)->by('login-ip:'.$request->ip()),
            Limit::perMinute(5)->by('login-who:'.sha1(mb_strtolower(trim((string) $request->input('email'))))),
        ]);

        RateLimiter::for('corporate-enquiries', fn (Request $request) => Limit::perHour(5)->by($request->ip()));

        ProductImage::observe(ProductImageObserver::class);
        Category::observe(CategoryObserver::class);
        Banner::observe(BannerObserver::class);
        HomepageSection::observe(HomepageSectionObserver::class);
        Order::observe(OrderObserver::class);
    }
}
