<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

/**
 * The printable gift card for an order: the customer's message and their name on one A6 card.
 * It never reads a price, an item or an address. Only signed-in admins who can manage orders see it.
 */
class GiftNoteController extends Controller
{
    public function __invoke(Order $order): View|RedirectResponse
    {
        $user = Auth::user();

        if ($user === null) {
            return redirect()->guest(route('filament.admin.auth.login'));
        }

        abort_unless($user->hasPermission('orders.manage'), 403);
        abort_unless($order->is_gift, 404);

        return view('orders.gift-note', [
            'message' => trim((string) $order->gift_message),
            'sender' => $order->customer?->name,
        ]);
    }
}
