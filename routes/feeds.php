<?php

use App\Http\Controllers\FeedController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// /feeds/google.xml, /feeds/meta.csv, /feeds/tiktok.csv -- each needs ?token=... (see Settings > Product feeds).
Route::get('/feeds/google.xml', fn (Request $request) => app(FeedController::class)->show($request, 'google'))->name('feeds.google');
Route::get('/feeds/meta.csv', fn (Request $request) => app(FeedController::class)->show($request, 'meta'))->name('feeds.meta');
Route::get('/feeds/tiktok.csv', fn (Request $request) => app(FeedController::class)->show($request, 'tiktok'))->name('feeds.tiktok');
