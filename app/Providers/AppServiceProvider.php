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
use App\Support\Contact;
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

        // Counted per visitor address, unless THROTTLE_BY_IP is off (see config/abuse.php): then only the per-person keys
        // below remain and the address-based ones step aside.
        $perIp = fn (Request $request, string $name, Limit $limit): array => config('abuse.throttle_by_ip')
            ? [$limit->by($name.':'.$request->ip())]
            : [];

        // Everything else in the API: a ceiling no real shopper reaches, that stops a script hammering the catalogue.
        RateLimiter::for('api', fn (Request $request) => $perIp($request, 'api', Limit::perMinute(300)) ?: Limit::none());

        // Placing an order: a person orders now and then; a script orders in bursts.
        RateLimiter::for('orders', fn (Request $request) => [
            ...$perIp($request, 'orders-min', Limit::perMinute(6)),
            ...$perIp($request, 'orders-hour', Limit::perHour(40)),
        ] ?: Limit::none());

        // Tracking an order takes its number and phone, so guessing is the only attack: limited per address, and per phone
        // number however many addresses it comes from.
        RateLimiter::for('order-lookup', function (Request $request) use ($perIp) {
            $who = Contact::phone((string) $request->query('phone')) ?? mb_strtolower(trim((string) $request->query('phone')));

            return [
                ...$perIp($request, 'lookup-min', Limit::perMinute(10)),
                Limit::perHour(20)->by('lookup-phone:'.sha1($who)),
            ];
        });

        // A courier's webhook: a handful an hour in practice; this only stops a flood at the door.
        RateLimiter::for('courier-webhook', fn (Request $request) => $perIp($request, 'courier-webhook', Limit::perMinute(240)) ?: Limit::none());

        RateLimiter::for('contact-form', fn (Request $request) => $perIp($request, 'contact', Limit::perHour(8)) ?: Limit::none());
        RateLimiter::for('newsletter', fn (Request $request) => $perIp($request, 'newsletter', Limit::perHour(10)) ?: Limit::none());
        RateLimiter::for('register', fn (Request $request) => $perIp($request, 'register', Limit::perHour(10)) ?: Limit::none());
        RateLimiter::for('landing-events', fn (Request $request) => $perIp($request, 'landing', Limit::perMinute(120)) ?: Limit::none());
        RateLimiter::for('social-login', fn (Request $request) => $perIp($request, 'social', Limit::perMinute(20)) ?: Limit::none());

        ProductImage::observe(ProductImageObserver::class);
        Category::observe(CategoryObserver::class);
        Banner::observe(BannerObserver::class);
        HomepageSection::observe(HomepageSectionObserver::class);
        Order::observe(OrderObserver::class);
    }
}
