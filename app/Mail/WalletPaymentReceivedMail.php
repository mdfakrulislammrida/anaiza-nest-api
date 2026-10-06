<?php

namespace App\Mail;

use App\Models\Order;
use App\Support\WalletPayments;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The plain note that tells the shop a wallet order is waiting to be checked. Plain text on purpose:
 * it is an internal notice, and the details to check against the wallet app are all in it.
 */
class WalletPaymentReceivedMail extends Mailable
{
    public function __construct(public Order $order) {}

    public function envelope(): Envelope
    {
        $label = WalletPayments::LABELS[$this->order->payment_method] ?? strtoupper($this->order->payment_method);

        return new Envelope(
            from: new Address(config('mail.from.address'), config('mail.from.name')),
            subject: "Payment to verify: order #{$this->order->id} ({$label})",
        );
    }

    public function content(): Content
    {
        return new Content(
            text: 'emails.wallet-payment-received',
            with: [
                'label' => WalletPayments::LABELS[$this->order->payment_method] ?? $this->order->payment_method,
                'adminUrl' => url('/admin/orders/'.$this->order->id.'/edit'),
            ],
        );
    }
}
