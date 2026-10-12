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
use App\Http\Controllers\Api\FaqController;
use App\Http\Controllers\Api\HomepageSectionController;
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
Route::post('/products/{product:slug}/reviews', [ProductReviewController::class, 'store'])->middleware('throttle:product-reviews');
Route::get('/products/{product:slug}/review-link', [ProductReviewController::class, 'link'])->middleware('throttle:product-reviews');
Route::get('/brands', [BrandController::class, 'index']);
Route::get('/tags', [ProductTagController::class, 'index']);
Route::get('/attributes', [ProductAttributeController::class, 'index']);

// Checkout (guest-friendly: creates/updates the customer record from the details submitted)
Route::post('/orders', [OrderController::class, 'store']);
Route::get('/orders/lookup', [OrderController::class, 'lookup']);

// Coupons are only ever checked on the server: this is a preview for the checkout field, and the order checks again.
Route::post('/coupons/validate', [CouponController::class, 'validateCode'])->middleware('throttle:30,1');

// Cart recovery: where a visitor has got to (stored only when there is a way to reach them), and a saved cart for a link.
Route::post('/checkout-sessions', [CheckoutSessionController::class, 'store'])->middleware('throttle:90,1');
Route::get('/recover/{token}', [CartRecoveryController::class, 'show'])->middleware('throttle:30,1');

Route::post('/contact-submissions', [ContactSubmissionController::class, 'store']);
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
Route::post('/newsletter-subscribers', [NewsletterSubscriberController::class, 'store']);
Route::get('/testimonials', [TestimonialController::class, 'index']);
Route::get('/media-library', [MediaItemController::class, 'index']);
Route::get('/homepage-sections', [HomepageSectionController::class, 'index']);

// Blog
Route::get('/articles', [ArticleController::class, 'index']);
Route::get('/articles/{slug}', [ArticleController::class, 'show']);

// Sitemap data (products, pages, articles) for the frontend to build sitemap.xml from
Route::get('/sitemap', [SitemapController::class, 'index']);

// Customer authentication
Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);

    // Choosing a new password: the link in the email proves ownership of the address. Every answer to a request for a link
    // is the same, whether or not there is an account.
    Route::post('/forgot-password', [PasswordResetController::class, 'forgot'])->middleware('throttle:password-reset');
    Route::get('/reset-password/check', [PasswordResetController::class, 'check'])->middleware('throttle:password-reset');
    Route::post('/reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:password-reset');

    Route::get('/google/redirect', [SocialAuthController::class, 'redirectToGoogle']);
    Route::get('/google/callback', [SocialAuthController::class, 'handleGoogleCallback']);
    Route::get('/facebook/redirect', [SocialAuthController::class, 'redirectToFacebook']);
    Route::get('/facebook/callback', [SocialAuthController::class, 'handleFacebookCallback']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
    });
});
