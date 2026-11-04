<?php

use App\Http\Controllers\Admin\GiftNoteController;
use App\Http\Controllers\Admin\InvoiceController as AdminInvoiceController;
use App\Http\Controllers\Admin\UploadPreviewController;
use App\Http\Controllers\Admin\WhatsAppActionController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'name' => 'Anaiza Nest API',
        'status' => 'ok',
    ]);
});

// A stored picture for the admin's upload fields, from the page's own address (see the controller). Signed-in admins only.
// The file's own path is the end of the address, so the field shows the real file name (an upload field takes the name from the address).
Route::get('/admin/upload-preview/{path}', UploadPreviewController::class)
    ->where('path', '.+')
    ->middleware('web')
    ->name('admin.upload-preview');
Route::get('/admin/upload-placeholder/{name}', [UploadPreviewController::class, 'placeholder'])
    ->where('name', '.+')
    ->middleware('web')
    ->name('admin.upload-placeholder');

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
