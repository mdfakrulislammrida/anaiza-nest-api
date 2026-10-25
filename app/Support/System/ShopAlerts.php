<?php

namespace App\Support\System;

use App\Mail\ShopAlertMail;
use App\Models\Order;
use App\Models\PaymentSetting;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SiteSetting;
use App\Support\Dhaka;
use App\Support\Email\TransactionalEmails;
use App\Support\WalletPayments;
use App\Support\WhatsApp;
use Illuminate\Support\Facades\Mail;

/**
 * The notes the shop's system sends to the shop itself. They go to Site settings > Shop alerts, or, when that is blank, to
 * the payment notification address and then the contact email.
 */
final class ShopAlerts
{
    /** How many products a stock digest lists before it says "and N more". */
    private const DIGEST_LIMIT = 40;

    public static function recipient(): ?string
    {
        $site = SiteSetting::query()->first();

        $to = $site?->alert_email ?: PaymentSetting::query()->value('payment_notify_email') ?: $site?->contact_email;

        return filled($to) ? (string) $to : null;
    }

    /**
     * A new order, after the response is sent. A wallet order already has its own "payment to verify" note, so only the
     * other kinds (cash on delivery) are announced here.
     */
    public static function newOrder(Order $order): void
    {
        if (WalletPayments::isWallet($order->payment_method)) {
            return;
        }

        $site = SiteSetting::query()->first();

        if ($site !== null && ! $site->alert_new_orders) {
            return;
        }

        $to = self::recipient();

        if ($to === null) {
            return;
        }

        $mail = self::newOrderMail($order->loadMissing(['customer', 'items']));

        TransactionalEmails::afterResponse(
            fn () => Mail::to($to)->send($mail),
            'New order alert email',
            ['order_id' => $order->id],
        );
    }

    public static function newOrderMail(Order $order): ShopAlertMail
    {
        $to = $order->recipient();
        $method = WalletPayments::LABELS[$order->payment_method] ?? strtoupper((string) $order->payment_method);
        $total = '৳'.number_format((int) $order->total);

        $lines = ["A new order has come in, to be paid by {$method}.", ''];
        $lines[] = "Order:       #{$order->id}";
        $lines[] = "Total:       {$total} (delivery ৳".number_format((int) $order->delivery_fee).')';
        $lines[] = 'Customer:    '.trim(($to['name'] ?? '').', '.($to['phone'] ?? ''), ', ');
        $lines[] = 'Deliver to:  '.($order->recipientAddressLine() ?: 'not given');
        $lines[] = '';
        $lines[] = 'Items:';

        foreach ($order->items as $item) {
            $lines[] = '  '.$item->quantity.' x '.$item->product_name;
        }

        if ($order->is_gift) {
            $lines[] = '';
            $lines[] = 'This is a gift'.($order->gift_message ? ': "'.$order->gift_message.'"' : '.');
        }

        if (filled($order->gift_note)) {
            $lines[] = 'Note: '.$order->gift_note;
        }

        $links = [];
        $name = trim((string) ($to['name'] ?? ''));
        $chat = WhatsApp::link(
            $to['phone'] ?? null,
            'Hello'.($name !== '' ? " {$name}" : '').", this is Anaiza Nest. We have your order #{$order->id} ({$total}). May we confirm the address and a good time for delivery?",
        );

        if ($chat !== null) {
            $links['Confirm with the customer on WhatsApp'] = $chat;
        }

        $links['Open the order'] = url('/admin/orders/'.$order->id.'/edit');

        return new ShopAlertMail("New order #{$order->id}: {$total}, {$method}", $lines, $links, $order);
    }

    /**
     * Products that are out, or at or below the "only a few left" number. Null when everything is comfortably stocked.
     *
     * @return array{out: list<string>, low: list<string>, more: int}|null
     */
    public static function stockDigest(): ?array
    {
        $threshold = max(0, (int) (SiteSetting::query()->value('low_stock_threshold') ?? 5));

        $out = [];
        $low = [];

        $line = fn (string $name, ?string $sku, int $stock): string => '  '.$name.($sku ? " ({$sku})" : '').': '.($stock <= 0 ? 'none left' : $stock.' left');

        foreach (Product::query()->where('is_active', true)->where('stock_quantity', '<=', $threshold)->orderBy('stock_quantity')->orderBy('name')->get(['name', 'sku', 'stock_quantity']) as $product) {
            $text = $line($product->name, $product->sku, (int) $product->stock_quantity);

            if ((int) $product->stock_quantity <= 0) {
                $out[] = $text;
            } else {
                $low[] = $text;
            }
        }

        // A colour or size with its own stock, on a product that is for sale.
        $variants = ProductVariant::query()->whereNotNull('stock_quantity')->where('stock_quantity', '<=', $threshold)
            ->whereHas('product', fn ($query) => $query->where('is_active', true))
            ->with('product:id,name')->orderBy('stock_quantity')->get();

        foreach ($variants as $variant) {
            $text = $line($variant->product->name.' - '.$variant->name.': '.$variant->value, $variant->sku, (int) $variant->stock_quantity);

            if ((int) $variant->stock_quantity <= 0) {
                $out[] = $text;
            } else {
                $low[] = $text;
            }
        }

        if ($out === [] && $low === []) {
            return null;
        }

        $total = count($out) + count($low);
        $shownOut = array_slice($out, 0, self::DIGEST_LIMIT);
        $shownLow = array_slice($low, 0, max(0, self::DIGEST_LIMIT - count($shownOut)));

        return ['out' => $shownOut, 'low' => $shownLow, 'more' => $total - count($shownOut) - count($shownLow)];
    }

    /**
     * Sends the day's stock note, once. Returns a sentence about what happened (the command prints it).
     */
    public static function sendStockDigest(bool $force = false): string
    {
        $site = SiteSetting::query()->first();

        if ($site !== null && ! $site->alert_low_stock) {
            return 'Low-stock alerts are switched off in Site settings.';
        }

        $to = self::recipient();

        if ($to === null) {
            return 'No alert email address is set (Site settings > Shop alerts).';
        }

        $last = SystemEvents::latest('low_stock_digest');

        if (! $force && $last !== null && Dhaka::day($last->updated_at) === Dhaka::day()) {
            return "Today's stock note was already sent.";
        }

        $digest = self::stockDigest();

        if ($digest === null) {
            return 'Every product is comfortably stocked; nothing to send.';
        }

        $lines = ['Here is where stock stands this morning.', ''];

        if ($digest['out'] !== []) {
            $lines[] = 'Finished:';
            array_push($lines, ...$digest['out']);
            $lines[] = '';
        }

        if ($digest['low'] !== []) {
            $lines[] = 'Running low:';
            array_push($lines, ...$digest['low']);
            $lines[] = '';
        }

        if ($digest['more'] > 0) {
            $lines[] = 'and '.$digest['more'].' more. The full list is under Products in the admin.';
            $lines[] = '';
        }

        $lines[] = 'You get this once a day, and only on days something needs a look. You can turn it off under Settings > Site settings > Shop alerts.';

        $count = count($digest['out']) + count($digest['low']) + $digest['more'];

        Mail::to($to)->send(new ShopAlertMail(
            "Stock note: {$count} ".($count === 1 ? 'product needs' : 'products need').' a look',
            $lines,
            ['Open the products' => url('/admin/products')],
        ));

        SystemEvents::mark('low_stock_digest', "Sent for {$count} products.");

        return "Sent the stock note ({$count} products) to {$to}.";
    }

    /** Tells the shop a backup did not happen. Never throws: the failure is already recorded. */
    public static function backupFailed(string $reason): void
    {
        try {
            $to = self::recipient();

            if ($to === null) {
                return;
            }

            Mail::to($to)->send(new ShopAlertMail(
                'The nightly backup did not finish',
                [
                    "The shop's nightly backup did not finish, so the newest backup is older than it should be.",
                    '',
                    'What went wrong: '.$reason,
                    '',
                    'Nothing is lost: the shop is running as normal. Open System health in the admin to see the backups there are, and to make one by hand once the cause is fixed.',
                ],
                ['Open System health' => url('/admin/system-health')],
            ));
        } catch (\Throwable) {
            // The failure is recorded and shows on the health page.
        }
    }
}
