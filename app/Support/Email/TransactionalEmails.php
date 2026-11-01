<?php

namespace App\Support\Email;

use App\Mail\OrderConfirmationMail;
use App\Mail\TransactionalMail;
use App\Models\Customer;
use App\Models\Order;
use App\Models\SiteSetting;
use App\Support\System\SystemEvents;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Sends the transactional emails without a queue worker, and without ever being able to break what triggered them.
 *
 * Production has no queue worker, so nothing here is queued: each email is sent right after the HTTP response has
 * been delivered (dispatch()->afterResponse()), a failure is logged and goes no further, and a customer with no
 * email address simply gets none.
 *
 * An order email that could not be sent (the mail server did not answer within MAIL_TIMEOUT, 10 seconds) is not lost: the order remembers it
 * (orders.emails_failed) and the scheduled task emails:retry sends it again, 15 minutes, 1 hour and 3 hours after each failure, within 24 hours
 * of the first. Only when those tries are used up does it reach Settings > System health. Whatever is sent is recorded first, so one email is never
 * sent twice.
 */
final class TransactionalEmails
{
    /** Minutes to wait after the 1st, 2nd and 3rd failure before trying again: so at most 3 more tries. */
    public const RETRY_AFTER_MINUTES = [15, 60, 180];

    public const WINDOW_HOURS = 24;

    /**
     * Runs $send once the response is on its way. Anything it throws is logged, never raised. With an order and a kind (confirmation, shipped ...) a failure
     * is remembered for a later try; without them it goes straight to System health.
     *
     * @param  array<string, mixed>  $context
     */
    public static function afterResponse(Closure $send, string $what, array $context = [], ?Order $order = null, ?string $kind = null): void
    {
        dispatch(function () use ($send, $what, $context, $order, $kind): void {
            try {
                $send();

                if ($order !== null && $kind !== null) {
                    self::forgetFailure($order, $kind);
                }
            } catch (\Throwable $e) {
                Log::error("{$what} failed.", $context + ['error' => $e->getMessage()]);

                if ($order !== null && $kind !== null) {
                    self::noteFailure($order, $kind, $what, $e);
                } else {
                    SystemEvents::record('mail_failed', "{$what}: ".$e->getMessage());
                }
            }
        })->afterResponse();
    }

    public static function orderConfirmation(Order $order): void
    {
        if ($order->isLegacy()) {
            return;
        }

        $order->loadMissing('customer');

        $email = $order->recipient()['email'];

        if (blank($email)) {
            return;
        }

        $site = SiteSetting::query()->first();

        self::afterResponse(
            fn () => Mail::to($email)->send(new OrderConfirmationMail($order->loadMissing(['customer', 'items']), $site)),
            'Order confirmation email',
            ['order_id' => $order->id],
            $order,
            'confirmation',
        );
    }

    /**
     * An order has just changed status. Shipped, delivered and cancelled each send their email, once per order:
     * what has been sent is recorded on the order, so moving the status back and forth never repeats one.
     */
    public static function orderStatusChanged(Order $order): void
    {
        if ($order->isLegacy()) {
            return;
        }

        $template = TemplateDefinitions::STATUS_TEMPLATES[$order->status] ?? null;
        $order->loadMissing('customer');

        if ($template === null || blank($order->recipient()['email'])) {
            return;
        }

        $status = $order->status;

        if (! self::claimStatus($order, $status)) {
            return;
        }

        $email = $order->recipient()['email'];
        $site = SiteSetting::query()->first();

        self::afterResponse(function () use ($order, $template, $email, $site, $status): void {
            try {
                Mail::to($email)->send(new TransactionalMail($template, $order->load(['customer', 'items.product']), $order->customer, $site));
            } catch (\Throwable $e) {
                // Not sent, so it is not recorded as sent: the next change to this status, or the retry, can try again.
                self::releaseStatus($order, $status);

                throw $e;
            }
        }, "Order {$status} email", ['order_id' => $order->id], $order, $status);
    }

    public static function welcome(Customer $customer): void
    {
        if (blank($customer->email)) {
            return;
        }

        $site = SiteSetting::query()->first();

        self::afterResponse(
            fn () => Mail::to($customer->email)->send(new TransactionalMail(TemplateDefinitions::WELCOME, null, $customer, $site)),
            'Welcome email',
            ['customer_id' => $customer->id],
        );
    }

    // ---- the retry (the scheduled task emails:retry)

    /**
     * Sends again the order emails that failed, when each is due. Stops when the time is up (the rest wait for the next run).
     *
     * @return array{sent: int, failed: int, dropped: int, waiting: int}
     */
    public static function retryFailed(?float $until = null): array
    {
        $until ??= microtime(true) + 40;
        $summary = ['sent' => 0, 'failed' => 0, 'dropped' => 0, 'waiting' => 0];

        foreach (Order::query()->whereNotNull('emails_failed')->orderBy('id')->get() as $order) {
            foreach ($order->emails_failed ?? [] as $kind => $info) {
                $tries = (int) ($info['tries'] ?? 1);
                $last = Carbon::parse($info['at'] ?? $info['first_at'] ?? now());
                $first = Carbon::parse($info['first_at'] ?? $info['at'] ?? now());

                if ($tries > count(self::RETRY_AFTER_MINUTES)) {
                    continue; // given up already
                }

                if ($last->copy()->addMinutes(self::RETRY_AFTER_MINUTES[$tries - 1])->isFuture()) {
                    $summary['waiting']++;

                    continue;
                }

                if (microtime(true) > $until) {
                    $summary['waiting']++;

                    continue;
                }

                if ($first->lessThan(now()->subHours(self::WINDOW_HOURS)) || $order->isLegacy() || blank($order->recipient()['email'])) {
                    self::giveUp($order, $kind, 'older than 24 hours or nobody to send it to');
                    $summary['dropped']++;

                    continue;
                }

                // A status email only goes if the order is still in that status: after "shipped" came "delivered", the shipped note is stale.
                if ($kind !== 'confirmation' && $order->status !== $kind) {
                    self::forgetFailure($order, $kind);
                    $summary['dropped']++;

                    continue;
                }

                self::sendNow($order, $kind) ? $summary['sent']++ : $summary['failed']++;
            }
        }

        return $summary;
    }

    /** One try, now, in this process. Returns whether the email was sent. */
    private static function sendNow(Order $order, string $kind): bool
    {
        $site = SiteSetting::query()->first();
        $email = $order->recipient()['email'];

        if ($kind !== 'confirmation' && ! self::claimStatus($order, $kind)) {
            // Already recorded as sent (by a status change meanwhile): nothing more to do.
            self::forgetFailure($order, $kind);

            return true;
        }

        try {
            if ($kind === 'confirmation') {
                Mail::to($email)->send(new OrderConfirmationMail($order->loadMissing(['customer', 'items']), $site));
            } else {
                $template = TemplateDefinitions::STATUS_TEMPLATES[$kind];
                Mail::to($email)->send(new TransactionalMail($template, $order->load(['customer', 'items.product']), $order->customer, $site));
            }
        } catch (\Throwable $e) {
            if ($kind !== 'confirmation') {
                self::releaseStatus($order, $kind);
            }

            Log::error("Order {$kind} email failed again.", ['order_id' => $order->id, 'error' => $e->getMessage()]);
            self::noteFailure($order, $kind, $kind === 'confirmation' ? 'Order confirmation email' : "Order {$kind} email", $e);

            return false;
        }

        self::forgetFailure($order, $kind);
        Log::info("Order {$kind} email sent on a retry.", ['order_id' => $order->id]);

        return true;
    }

    // ---- what the order remembers

    /** The claim: whoever records the status first sends; any other attempt finds it recorded and stops. */
    private static function claimStatus(Order $order, string $status): bool
    {
        return DB::transaction(function () use ($order, $status): bool {
            $row = Order::query()->lockForUpdate()->find($order->id);
            $sent = $row?->emails_sent ?? [];

            if ($row === null || isset($sent[$status])) {
                return false;
            }

            $sent[$status] = now()->toIso8601String();
            $row->forceFill(['emails_sent' => $sent])->saveQuietly();
            $order->forceFill(['emails_sent' => $sent])->syncOriginal();

            return true;
        });
    }

    private static function releaseStatus(Order $order, string $status): void
    {
        $row = Order::query()->find($order->id);
        $sent = $row?->emails_sent ?? [];
        unset($sent[$status]);
        $row?->forceFill(['emails_sent' => $sent ?: null])->saveQuietly();
    }

    /** A try failed: remember it for the next one; when the tries are used up, tell System health. */
    private static function noteFailure(Order $order, string $kind, string $what, \Throwable $e): void
    {
        try {
            $row = Order::query()->find($order->id);

            if ($row === null) {
                return;
            }

            $failed = $row->emails_failed ?? [];
            $tries = (int) ($failed[$kind]['tries'] ?? 0) + 1;
            $failed[$kind] = ['tries' => $tries, 'at' => now()->toIso8601String(), 'first_at' => $failed[$kind]['first_at'] ?? now()->toIso8601String()];
            $row->forceFill(['emails_failed' => $failed])->saveQuietly();

            if ($tries > count(self::RETRY_AFTER_MINUTES)) {
                SystemEvents::record('mail_failed', "{$what} for order #{$order->id} could not be sent after {$tries} tries. Last error: ".$e->getMessage());
            }
        } catch (\Throwable) {
            // Remembering is best effort: the email failing must stay a log line, not become a second problem.
            SystemEvents::record('mail_failed', "{$what}: ".$e->getMessage());
        }
    }

    private static function forgetFailure(Order $order, string $kind): void
    {
        $row = Order::query()->find($order->id);
        $failed = $row?->emails_failed ?? [];

        if (! isset($failed[$kind])) {
            return;
        }

        unset($failed[$kind]);
        $row?->forceFill(['emails_failed' => $failed ?: null])->saveQuietly();
    }

    private static function giveUp(Order $order, string $kind, string $why): void
    {
        $row = Order::query()->find($order->id);
        $failed = $row?->emails_failed ?? [];
        unset($failed[$kind]);
        $row?->forceFill(['emails_failed' => $failed ?: null])->saveQuietly();

        SystemEvents::record('mail_failed', "Order {$kind} email for order #{$order->id} was not sent and is not tried again ({$why}).");
    }
}
