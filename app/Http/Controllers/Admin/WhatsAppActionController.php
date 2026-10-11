<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AbandonedCheckout;
use App\Models\CartRecoverySetting;
use App\Models\Order;
use App\Models\ReviewRequestSetting;
use App\Support\Recovery\RecoveryEmails;
use App\Support\Reviews\ReviewLinks;
use App\Support\WhatsApp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

/**
 * The two "send on WhatsApp" buttons in the admin. There is no WhatsApp sending here: each opens a chat with the message
 * ready, and the click is what is recorded (so the list can show who has been asked). Signed-in admins only.
 */
class WhatsAppActionController extends Controller
{
    public function reviewRequest(Order $order): RedirectResponse
    {
        $user = Auth::user();

        if ($user === null) {
            return redirect()->guest(route('filament.admin.auth.login'));
        }

        abort_unless($user->hasPermission('orders.manage'), 403);

        $order->loadMissing(['customer', 'items.product']);
        $settings = ReviewRequestSetting::current();
        $links = $order->items
            ->filter(fn ($item) => filled($item->product?->slug))
            ->map(fn ($item) => $order->items->count() > 1 ? $item->product_name.': '.ReviewLinks::url($item) : ReviewLinks::url($item))
            ->implode("\n");

        $message = WhatsApp::fill($settings->whatsappMessage(), [
            'name' => filled($order->customer?->name) ? $order->customer->name : 'there',
            'order' => $order->id,
            'link' => $links,
        ]);

        $url = WhatsApp::link($order->customer?->phone, $message);
        abort_if($url === null, 404);

        $order->forceFill(['review_whatsapp_clicked_at' => now()])->saveQuietly();

        return redirect()->away($url);
    }

    public function checkout(AbandonedCheckout $checkout): RedirectResponse
    {
        $user = Auth::user();

        if ($user === null) {
            return redirect()->guest(route('filament.admin.auth.login'));
        }

        abort_unless($user->hasPermission('abandoned_checkouts.manage'), 403);
        // Only for people who ticked the WhatsApp box: nothing is opened for anyone else.
        abort_unless($checkout->whatsapp_optin && filled($checkout->phone), 403);

        $message = WhatsApp::fill(CartRecoverySetting::current()->whatsappMessage(), [
            'name' => filled($checkout->name) ? $checkout->name : 'there',
            'link' => RecoveryEmails::recoveryLink($checkout),
        ]);

        $url = WhatsApp::link($checkout->phone, $message);
        abort_if($url === null, 404);

        $checkout->forceFill(['whatsapp_clicked_at' => now()])->saveQuietly();

        return redirect()->away($url);
    }
}
