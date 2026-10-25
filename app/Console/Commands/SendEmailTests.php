<?php

namespace App\Console\Commands;

use App\Mail\TransactionalMail;
use App\Models\Order;
use App\Models\SiteSetting;
use App\Support\Email\SampleData;
use App\Support\Email\TemplateDefinitions;
use App\Support\System\ShopAlerts;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class SendEmailTests extends Command
{
    protected $signature = 'emails:send-tests
        {--to= : Where to send them (default: the shop alert address in Site settings)}
        {--order= : The number of a real order to show in them (default: a made-up one)}';

    protected $description = 'Send one test of every email the shop sends (with the invoice on the confirmation, and the new-order note) to the admin address.';

    public function handle(): int
    {
        $to = $this->option('to') ?: ShopAlerts::recipient();

        if (blank($to)) {
            $this->error('There is no address to send to. Pass --to=you@example.com, or fill in Site settings > Shop alerts.');

            return self::FAILURE;
        }

        $order = filled($this->option('order'))
            ? Order::query()->with(['customer', 'items.product'])->findOrFail((int) $this->option('order'))
            : SampleData::order();
        $customer = $order->customer ?? SampleData::customer();
        $site = SiteSetting::query()->first() ?? new SiteSetting;

        $failed = 0;

        foreach (TemplateDefinitions::KEYS as $key) {
            $failed += $this->send($key, fn () => Mail::to($to)->send(new TransactionalMail($key, $order, $customer, $site, null, SampleData::context($key))));
        }

        $failed += $this->send('new_order_note', fn () => Mail::to($to)->send(ShopAlerts::newOrderMail($order)));

        $this->line($failed === 0 ? "All sent to {$to} with the \"".config('mail.default').'" mailer.' : "{$failed} could not be sent.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function send(string $name, callable $send): int
    {
        try {
            $send();
            $this->info("Sent: {$name}");

            return 0;
        } catch (\Throwable $e) {
            $this->error("Not sent: {$name} ({$e->getMessage()})");

            return 1;
        }
    }
}
