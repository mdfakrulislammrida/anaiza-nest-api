<?php

use App\Http\Controllers\Api\ArticleController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BannerController;
use App\Http\Controllers\Api\BrandController;
use App\Http\Controllers\Api\CartRecoveryController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\CheckoutSessionController;
use App\Http\Controllers\Api\ContactSubmissionController;
use App\Http\Controllers\Api\CookieConsentController;
use App\Http\Controllers\Api\CorporateEnquiryController;
use App\Http\Controllers\Api\CouponController;
use App\Http\Controllers\Api\CourierWebhookController;
use App\Http\Controllers\Api\FaqController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\HomepageSectionController;
use App\Http\Controllers\Api\LandingPageController;
use App\Http\Controllers\Api\MarketingSettingController;
use App\Http\Controllers\Api\MediaItemController;
use App\Http\Controllers\Api\NewsletterSubscriberController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PageController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\PaymentSettingController;
use App\Http\Controllers\Api\PopupSettingController;
use App\Http\Controllers\Api\ProductAttributeController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ProductReviewController;
use App\Http\Controllers\Api\ProductTagController;
use App\Http\Controllers\Api\ShippingZoneController;
use App\Http\Controllers\Api\SitemapController;
use App\Http\Controllers\Api\SiteSettingController;
use App\Http\Controllers\Api\SocialAuthController;
use App\Http\Controllers\Api\TestimonialController;
use Illuminate\Support\Facades\Route;

// Public catalog
Route::get('/categories', [CategoryController::class, 'index']);
Route::get('/categories/{category:slug}', [CategoryController::class, 'show']);
Route::get('/products', [ProductController::class, 'index']);
Route::get('/products/{product:slug}', [ProductController::class, 'show']);
Route::get('/products/{product:slug}/reviews', [ProductReviewController::class, 'index']);
Route::post('/products/{product:slug}/reviews', [ProductReviewController::class, 'store'])->middleware(['throttle:product-reviews', 'form-proof:form,reject']);
Route::get('/products/{product:slug}/review-link', [ProductReviewController::class, 'link'])->middleware('throttle:product-reviews');
Route::get('/brands', [BrandController::class, 'index']);
Route::get('/tags', [ProductTagController::class, 'index']);
Route::get('/attributes', [ProductAttributeController::class, 'index']);

// Checkout (guest-friendly: creates/updates the customer record from the details submitted)
Route::post('/orders', [OrderController::class, 'store'])->middleware(['throttle:orders', 'form-proof:order,reject']);
Route::get('/orders/lookup', [OrderController::class, 'lookup'])->middleware('throttle:order-lookup');

// Couriers report back here. Steadfast signs every request; the receiver refuses anything it cannot verify (see App\Support\Courier\SteadfastWebhook).
Route::post('/webhooks/steadfast', [CourierWebhookController::class, 'steadfast'])->middleware('throttle:courier-webhook');

// Coupons are only ever checked on the server: this is a preview for the checkout field, and the order checks again.
Route::post('/coupons/validate', [CouponController::class, 'validateCode'])->middleware('throttle:30,1');

// Cart recovery: where a visitor has got to (stored only when there is a way to reach them), and a saved cart for a link.
Route::post('/checkout-sessions', [CheckoutSessionController::class, 'store'])->middleware('throttle:90,1');
Route::get('/recover/{token}', [CartRecoveryController::class, 'show'])->middleware('throttle:30,1');

Route::post('/contact-submissions', [ContactSubmissionController::class, 'store'])->middleware(['throttle:contact-form', 'form-proof:form,pretend']);
Route::post('/corporate-enquiries', [CorporateEnquiryController::class, 'store'])->middleware('throttle:corporate-enquiries');

// Storefront content (mirrors what's managed in the Filament admin panel, so
// the Next.js frontend doesn't need any of this hardcoded)
Route::get('/pages', [PageController::class, 'index']);
Route::get('/pages/{page:slug}', [PageController::class, 'show']);
Route::get('/banners', [BannerController::class, 'index']);
Route::get('/faqs', [FaqController::class, 'index']);
Route::get('/shipping-zones', [ShippingZoneController::class, 'index']);
Route::get('/site-settings', [SiteSettingController::class, 'show']);
Route::get('/payment-settings', [PaymentSettingController::class, 'show']);
Route::get('/marketing-settings', [MarketingSettingController::class, 'show']);
Route::get('/cookie-consent', [CookieConsentController::class, 'show']);
Route::get('/popup-settings', [PopupSettingController::class, 'show']);
Route::post('/newsletter-subscribers', [NewsletterSubscriberController::class, 'store'])->middleware(['throttle:newsletter', 'form-proof:newsletter,pretend']);
Route::get('/testimonials', [TestimonialController::class, 'index']);
Route::get('/media-library', [MediaItemController::class, 'index']);
Route::get('/homepage-sections', [HomepageSectionController::class, 'index']);

// Blog
Route::get('/articles', [ArticleController::class, 'index']);
Route::get('/articles/{slug}', [ArticleController::class, 'show']);

// Sitemap data (products, pages, articles) for the frontend to build sitemap.xml from
Route::get('/sitemap', [SitemapController::class, 'index']);

// Landing pages built in the admin (/lp/<slug> on the storefront).
Route::get('/landing-pages', [LandingPageController::class, 'index']);
Route::get('/landing-pages/{slug}', [LandingPageController::class, 'show']);
Route::post('/landing-pages/{slug}/events', [LandingPageController::class, 'event'])->middleware('throttle:landing-events');

// For an outside uptime monitor: answers ok or down, and nothing else.
Route::get('/health', [HealthController::class, 'show'])->middleware('throttle:60,1');

// Customer authentication
Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register'])->middleware(['throttle:register', 'form-proof:form,reject']);
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:customer-login');

    // Choosing a new password: the link in the email proves ownership of the address. Every answer to a request for a link
    // is the same, whether or not there is an account.
    Route::post('/forgot-password', [PasswordResetController::class, 'forgot'])->middleware('throttle:password-reset');
    Route::get('/reset-password/check', [PasswordResetController::class, 'check'])->middleware('throttle:password-reset');
    Route::post('/reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:password-reset');

    Route::middleware('throttle:social-login')->group(function () {
        // The callback routes are named because the address registered with Google and Facebook is worked out from them
        // (IntegrationSetting::callbackUrl), not typed in anywhere.
        Route::get('/google/redirect', [SocialAuthController::class, 'redirectToGoogle'])->name('social.google.redirect');
        Route::get('/google/callback', [SocialAuthController::class, 'handleGoogleCallback'])->name('social.google.callback');
        Route::get('/facebook/redirect', [SocialAuthController::class, 'redirectToFacebook'])->name('social.facebook.redirect');
        Route::get('/facebook/callback', [SocialAuthController::class, 'handleFacebookCallback'])->name('social.facebook.callback');

        // The browser returns from a provider with a one-time code (never the sign-in token itself, which would sit in the
        // address bar and the history); this exchanges the code for the token.
        Route::post('/social/exchange', [SocialAuthController::class, 'exchange']);
    });

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::post('/logout-all', [AuthController::class, 'logoutEverywhere']);
        Route::get('/me', [AuthController::class, 'me']);
    });
});
