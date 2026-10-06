<?php

namespace App\Mail;

use App\Models\Customer;
use App\Models\Order;
use App\Models\SiteSetting;
use App\Support\Email\EmailBuilder;
use App\Support\Email\TemplateDefinitions;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Symfony\Component\Mime\Email;

/**
 * One of the five transactional emails (see TemplateDefinitions). The wording comes from the admin's template or the
 * built-in one; the branded layout, the sending and the invoice attachment on the confirmation are fixed, so no
 * template can break any of them.
 */
class TransactionalMail extends Mailable
{
    /** @var array{subject: string, html: string, text: string}|null */
    private ?array $built = null;

    /**
     * @param  array<string, mixed>|null  $override  unsaved wording, for the admin's preview and test email
     */
    public function __construct(
        public string $templateKey,
        public ?Order $order,
        public ?Customer $customer,
        public ?SiteSetting $siteSetting,
        public ?array $override = null,
    ) {}

    /**
     * @return array{subject: string, html: string, text: string}
     */
    private function built(): array
    {
        return $this->built ??= EmailBuilder::build($this->templateKey, $this->order, $this->customer, $this->siteSetting, $this->override);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(config('mail.from.address'), config('mail.from.name')),
            subject: $this->built()['subject'],
        );
    }

    public function content(): Content
    {
        $this->withSymfonyMessage(function (Email $message): void {
            $message->text($this->built()['text']);
        });

        return new Content(htmlString: $this->built()['html']);
    }

    public function attachments(): array
    {
        // The invoice rides with the confirmation, whatever the wording says.
        if ($this->templateKey !== TemplateDefinitions::CONFIRMATION || $this->order === null) {
            return [];
        }

        $pdf = Pdf::loadView('pdf.invoice', [
            'order' => $this->order,
            'siteSetting' => $this->siteSetting ?? SiteSetting::query()->first(),
        ]);

        return [
            Attachment::fromData(fn () => $pdf->output(), "invoice-{$this->order->id}.pdf")
                ->withMime('application/pdf'),
        ];
    }
}
