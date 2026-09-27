<?php

use App\Http\Controllers\Api\ArticleController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BannerController;
use App\Http\Controllers\Api\BrandController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\ContactSubmissionController;
use App\Http\Controllers\Api\FaqController;
use App\Http\Controllers\Api\HomepageSectionController;
use App\Http\Controllers\Api\MarketingSettingController;
use App\Http\Controllers\Api\MediaItemController;
use App\Http\Controllers\Api\NewsletterSubscriberController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PageController;
use App\Http\Controllers\Api\PaymentSettingController;
use App\Http\Controllers\Api\ProductAttributeController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ProductTagController;
use App\Http\Controllers\Api\ShippingZoneController;
use App\Http\Controllers\Api\SitemapController;
use App\Http\Controllers\Api\SiteSettingController;
use App\Http\Controllers\Api\SocialAuthController;
use App\Http\Controllers\Api\TestimonialController;
use Illuminate\Support\Facades\Route;

// Public catalog
Route::get('/categories', [CategoryController::class, 'index']);
Route::get('/products', [ProductController::class, 'index']);
Route::get('/products/{product:slug}', [ProductController::class, 'show']);
Route::get('/brands', [BrandController::class, 'index']);
Route::get('/tags', [ProductTagController::class, 'index']);
Route::get('/attributes', [ProductAttributeController::class, 'index']);

// Checkout (guest-friendly: creates/updates the customer record from the details submitted)
Route::post('/orders', [OrderController::class, 'store']);
Route::get('/orders/lookup', [OrderController::class, 'lookup']);

Route::post('/contact-submissions', [ContactSubmissionController::class, 'store']);

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

    Route::get('/google/redirect', [SocialAuthController::class, 'redirectToGoogle']);
    Route::get('/google/callback', [SocialAuthController::class, 'handleGoogleCallback']);
    Route::get('/facebook/redirect', [SocialAuthController::class, 'redirectToFacebook']);
    Route::get('/facebook/callback', [SocialAuthController::class, 'handleFacebookCallback']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
    });
});
