<?php

use App\Http\Controllers\Admin\GiftNoteController;
use App\Http\Controllers\Admin\WhatsAppActionController;
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

// Open WhatsApp with the message ready, recording the click. Signed-in admins only; the controller checks the permission.
Route::get('/admin/orders/{order}/whatsapp-review', [WhatsAppActionController::class, 'reviewRequest'])
    ->middleware('web')
    ->name('orders.whatsapp-review');
Route::get('/admin/abandoned-checkouts/{checkout}/whatsapp', [WhatsAppActionController::class, 'checkout'])
    ->middleware('web')
    ->name('checkouts.whatsapp');
