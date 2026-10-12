<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * A plain note from the shop's own system to the shop: a new order, stock running low, a backup that failed. Plain text on
 * purpose, so it is readable in any mail app and on a phone; the links are on their own lines.
 */
class ShopAlertMail extends Mailable
{
    /**
     * @param  list<string>  $lines  the body, one entry per line (an empty string is a blank line)
     * @param  array<string, string>  $links  label => address
     */
    public function __construct(public string $alertSubject, public array $lines, public array $links = []) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(config('mail.from.address'), config('mail.from.name')),
            subject: $this->alertSubject,
        );
    }

    public function content(): Content
    {
        return new Content(text: 'emails.shop-alert', with: ['lines' => $this->lines, 'links' => $this->links]);
    }
}
