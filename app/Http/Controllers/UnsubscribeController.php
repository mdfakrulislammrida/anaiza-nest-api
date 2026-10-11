<?php

namespace App\Http\Controllers;

use App\Models\AbandonedCheckout;
use App\Models\EmailOptOut;
use App\Support\Recovery\Unsubscribe;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class UnsubscribeController extends Controller
{
    /**
     * One click, honoured at once: opening the link (or a mail client's one-click POST) records the opt-out and stops every
     * waiting cart reminder for that email and phone.
     */
    public function __invoke(Request $request, string $token): Response
    {
        $who = Unsubscribe::resolve($token);

        if ($who === null) {
            return response()->view('unsubscribe', ['ok' => false, 'who' => null], 404);
        }

        EmailOptOut::record($who['email'], $who['phone'], 'email_link');

        AbandonedCheckout::query()
            ->whereNotIn('status', [AbandonedCheckout::STATUS_RECOVERED, AbandonedCheckout::STATUS_ORDERED])
            ->where(function ($query) use ($who) {
                if ($who['email']) {
                    $query->orWhere('email', $who['email']);
                }
                if ($who['phone']) {
                    $query->orWhere('phone', $who['phone']);
                }
            })
            ->update(['unsubscribed' => true, 'status' => AbandonedCheckout::STATUS_UNSUBSCRIBED]);

        return response()->view('unsubscribe', ['ok' => true, 'who' => $who['email'] ?? $who['phone']]);
    }
}
