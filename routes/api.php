<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BannerController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\FaqController;
use App\Http\Controllers\Api\NewsletterSubscriberController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PageController;
use App\Http\Controllers\Api\PaymentSettingController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ShippingZoneController;
use App\Http\Controllers\Api\SiteSettingController;
use Illuminate\Support\Facades\Route;

// Public catalog
Route::get('/categories', [CategoryController::class, 'index']);
Route::get('/products', [ProductController::class, 'index']);
Route::get('/products/{product:slug}', [ProductController::class, 'show']);

// Checkout (guest-friendly: creates/updates the customer record from the details submitted)
Route::post('/orders', [OrderController::class, 'store']);

// Storefront content (mirrors what's managed in the Filament admin panel, so
// the Next.js frontend doesn't need any of this hardcoded)
Route::get('/pages', [PageController::class, 'index']);
Route::get('/pages/{page:slug}', [PageController::class, 'show']);
Route::get('/banners', [BannerController::class, 'index']);
Route::get('/faqs', [FaqController::class, 'index']);
Route::get('/shipping-zones', [ShippingZoneController::class, 'index']);
Route::get('/site-settings', [SiteSettingController::class, 'show']);
Route::get('/payment-settings', [PaymentSettingController::class, 'show']);
Route::post('/newsletter-subscribers', [NewsletterSubscriberController::class, 'store']);

// Customer authentication
Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
    });
});
