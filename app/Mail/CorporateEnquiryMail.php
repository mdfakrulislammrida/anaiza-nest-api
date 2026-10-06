<?php

namespace App\Mail;

use App\Models\CorporateEnquiry;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The plain note that tells the team a corporate enquiry has arrived. Plain text on purpose: it is an
 * internal notice, not a customer email, and the reply goes to the enquirer directly.
 */
class CorporateEnquiryMail extends Mailable
{
    public function __construct(public CorporateEnquiry $enquiry) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(config('mail.from.address'), config('mail.from.name')),
            replyTo: [new Address($this->enquiry->email, $this->enquiry->name)],
            subject: 'Corporate enquiry: '.$this->enquiry->company,
        );
    }

    public function content(): Content
    {
        return new Content(text: 'emails.corporate-enquiry');
    }
}
