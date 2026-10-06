<?php

use App\Http\Controllers\Admin\GiftNoteController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'name' => 'Anaiza Nest API',
        'status' => 'ok',
    ]);
});

// The printable gift card for an order. Signed-in admins only; the controller checks the permission.
Route::get('/admin/orders/{order}/gift-note', GiftNoteController::class)
    ->middleware('web')
    ->name('orders.gift-note');
