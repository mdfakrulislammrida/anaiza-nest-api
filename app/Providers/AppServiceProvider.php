<?php

namespace App\Providers;

use App\Models\Category;
use App\Models\HomepageSection;
use App\Models\ProductImage;
use App\Observers\CategoryObserver;
use App\Observers\HomepageSectionObserver;
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
        RateLimiter::for('corporate-enquiries', fn (Request $request) => Limit::perHour(5)->by($request->ip()));

        ProductImage::observe(ProductImageObserver::class);
        Category::observe(CategoryObserver::class);
        HomepageSection::observe(HomepageSectionObserver::class);
    }
}
