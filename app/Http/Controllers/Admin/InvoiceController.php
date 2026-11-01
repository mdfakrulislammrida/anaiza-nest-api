<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Api\InvoiceController as CustomerInvoiceController;
use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

/**
 * The invoice PDF of any order, for a signed-in admin who can manage orders. The same file the customer gets.
 */
class InvoiceController extends Controller
{
    public function __invoke(Order $order, CustomerInvoiceController $invoices): Response|RedirectResponse
    {
        $user = Auth::user();

        if ($user === null) {
            return redirect()->guest(route('filament.admin.auth.login'));
        }

        abort_unless($user->hasPermission('orders.manage'), 403);

        $result = $invoices->respond($order->load(['customer', 'items']), 'admin');

        // An admin is looking at a page, so a failure is shown as words, not as JSON.
        if ($result->getStatusCode() !== 200) {
            abort(500, (string) ($result->getData()->message ?? 'The invoice could not be made.'));
        }

        return $result;
    }
}
