<?php

namespace App\Mail;

use App\Models\Order;
use App\Models\SiteSetting;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class OrderConfirmationMail extends Mailable
{
    public function __construct(
        public Order $order,
        public ?SiteSetting $siteSetting,
    ) {}

    public function envelope(): Envelope
    {
        $siteName = $this->siteSetting?->site_name ?: config('app.name');

        return new Envelope(
            from: new Address(config('mail.from.address'), $siteName),
            subject: "Order #{$this->order->id} confirmed - {$siteName}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.orders.confirmation',
            with: [
                'order' => $this->order,
                'siteSetting' => $this->siteSetting,
            ],
        );
    }

    public function attachments(): array
    {
        $pdf = Pdf::loadView('pdf.invoice', [
            'order' => $this->order,
            'siteSetting' => $this->siteSetting,
        ]);

        return [
            Attachment::fromData(fn () => $pdf->output(), "invoice-{$this->order->id}.pdf")
                ->withMime('application/pdf'),
        ];
    }
}
