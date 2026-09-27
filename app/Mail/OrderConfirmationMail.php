<?php

namespace App\Mail;

use App\Models\EmailTemplate;
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
    private ?array $resolvedTemplate = null;

    public function __construct(
        public Order $order,
        public ?SiteSetting $siteSetting,
    ) {}

    public function envelope(): Envelope
    {
        $siteName = $this->siteSetting?->site_name ?: config('app.name');

        return new Envelope(
            from: new Address(config('mail.from.address'), $siteName),
            subject: $this->resolveTemplate()['subject'],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.orders.confirmation',
            with: [
                'order' => $this->order,
                'siteSetting' => $this->siteSetting,
                'introHtml' => $this->resolveTemplate()['body_html'],
            ],
        );
    }

    /**
     * Only the subject + the intro/greeting section are admin-customizable
     * (see EmailTemplate::ORDER_CONFIRMATION) -- everything else in the
     * email (branded header, order summary table, invoice attachment,
     * footer) is fixed Blade/PHP, so a template can never break the layout
     * or drop the invoice. Falls back to today's hardcoded wording, run
     * through the identical placeholder substitution, whenever no active
     * template exists.
     *
     * @return array{subject: string, body_html: string}
     */
    private function resolveTemplate(): array
    {
        if ($this->resolvedTemplate !== null) {
            return $this->resolvedTemplate;
        }

        $customer = $this->order->customer;
        $siteName = $this->siteSetting?->site_name ?: config('app.name');

        $tokens = [
            '{{order_number}}' => (string) $this->order->id,
            '{{customer_name}}' => $customer->name,
            '{{site_name}}' => $siteName,
            '{{order_total}}' => '৳'.number_format($this->order->total),
            '{{order_date}}' => $this->order->created_at->format('d M Y, h:i A'),
            '{{payment_method}}' => strtoupper($this->order->payment_method),
            '{{delivery_address}}' => "{$customer->address}, {$customer->thana}, {$customer->district}, {$customer->division}",
        ];

        $template = EmailTemplate::query()
            ->where('key', EmailTemplate::ORDER_CONFIRMATION)
            ->where('is_active', true)
            ->first();

        if ($template) {
            return $this->resolvedTemplate = $template->render($tokens);
        }

        $default = new EmailTemplate([
            'subject' => EmailTemplate::defaultOrderConfirmationSubject(),
            'body_html' => EmailTemplate::defaultOrderConfirmationBodyHtml(),
        ]);

        return $this->resolvedTemplate = $default->render($tokens);
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
