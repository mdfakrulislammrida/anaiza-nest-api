<?php

use App\Http\Controllers\Admin\GiftNoteController;
use App\Http\Controllers\Admin\InvoiceController as AdminInvoiceController;
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

// The invoice PDF for an order, for signed-in admins who can manage orders (the controller checks the permission).
Route::get('/admin/orders/{order}/invoice', AdminInvoiceController::class)
    ->middleware('web')
    ->name('orders.invoice');

// Open WhatsApp with the message ready, recording the click. Signed-in admins only; the controller checks the permission.
Route::get('/admin/orders/{order}/whatsapp-review', [WhatsAppActionController::class, 'reviewRequest'])
    ->middleware('web')
    ->name('orders.whatsapp-review');
Route::get('/admin/abandoned-checkouts/{checkout}/whatsapp', [WhatsAppActionController::class, 'checkout'])
    ->middleware('web')
    ->name('checkouts.whatsapp');
