<?php

use App\Http\Controllers\UnsubscribeController;
use Illuminate\Support\Facades\Route;

// Links in emails. They are opened without a session (and a mail client's one-click unsubscribe is a bare POST), so they
// live outside the web group: no cookies, no CSRF token.
Route::match(['get', 'post'], '/unsubscribe/{token}', UnsubscribeController::class)->name('unsubscribe');
