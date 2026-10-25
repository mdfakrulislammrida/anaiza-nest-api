<?php

namespace App\Mail;

use App\Models\Order;
use App\Models\SiteSetting;
use App\Support\Email\EmailBuilder;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * A note from the shop's own system to the shop: a new order, stock running low, a backup that failed. Plain text, so it is readable
 * in any mail app and on a phone, with the links on their own lines. A new-order note also carries an HTML version in the shop's
 * branded frame, with the small picture of each piece ordered; mail apps that cannot show it show the plain text.
 */
class ShopAlertMail extends Mailable
{
    /**
     * @param  list<string>  $lines  the body, one entry per line (an empty string is a blank line)
     * @param  array<string, string>  $links  label => address
     * @param  Order|null  $order  set for a new-order note, which then also gets the HTML version
     */
    public function __construct(public string $alertSubject, public array $lines, public array $links = [], public ?Order $order = null) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(config('mail.from.address'), config('mail.from.name')),
            subject: $this->alertSubject,
        );
    }

    public function content(): Content
    {
        $with = ['lines' => $this->lines, 'links' => $this->links];

        if ($this->order === null) {
            return new Content(text: 'emails.shop-alert', with: $with);
        }

        $order = $this->order->loadMissing(['customer', 'items.product']);
        $site = SiteSetting::query()->first() ?? new SiteSetting;

        $body = view('emails.blocks.new-order', [
            'order' => $order,
            'headline' => $this->lines[0] ?? 'A new order has come in.',
            'links' => $this->links,
        ])->render();

        return new Content(htmlString: EmailBuilder::layout($body, $this->alertSubject, $site), text: 'emails.shop-alert', with: $with);
    }
}
