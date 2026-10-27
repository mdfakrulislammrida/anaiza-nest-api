<?php

namespace App\Support;

use App\Jobs\SendMetaConversionEvent;
use App\Jobs\SendTikTokConversionEvent;
use App\Models\ConversionAttempt;
use App\Models\Order;
use App\Support\System\SystemEvents;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The log of every try at reporting a Purchase to Meta or TikTok from the shop's own server, and the rules for trying again.
 *
 * A try ends in one of three ways: sent, failed, or skipped (switched off, or no pixel and token set up). A failure is worth repeating only when
 * the platform may simply have been unreachable: no answer, a timeout, "too many requests" (429) or its own trouble (5xx). A 400, a 401, a 403 (a
 * bad token, bad data) will fail the same way again, so it is not repeated and goes to Settings > System health.
 *
 * Automatic tries: at most 3 after the first, 10 minutes, 30 minutes and 2 hours after the failure before, and only while the first try is less than
 * 24 hours old. The Meta event id never changes (order-<number>), so a try that did reach Meta before the answer was lost is merged by Meta.
 */
final class ConversionAttempts
{
    /** @var array<string, string> */
    public const PLATFORMS = ['meta' => 'Meta', 'tiktok' => 'TikTok'];

    public const RETRY_DELAYS_MINUTES = [10, 30, 120];

    public const WINDOW_HOURS = 24;

    /** "Send again" cannot be pressed twice in a row: a platform is not tried again within this many seconds of its last try. */
    public const RESEND_PAUSE_SECONDS = 60;

    public static function latest(Order $order, string $platform): ?ConversionAttempt
    {
        return ConversionAttempt::query()->where('order_id', $order->id)->where('platform', $platform)->orderByDesc('id')->first();
    }

    /** Whether trying again could help. $code is TikTok's own code in the body of a 200, $transient is Meta's "try again" flag. */
    public static function retryable(?int $httpStatus, ?int $code = null, bool $transient = false): bool
    {
        if ($httpStatus === null) {
            return true; // no answer at all: a timeout, a refused connection
        }

        return $transient || $httpStatus === 429 || $httpStatus >= 500 || ($code !== null && ($code === 40100 || $code >= 50000));
    }

    /**
     * Writes one try down and, for a failure that could work next time, says when the next automatic try is due. Best effort: the order never
     * waits on this, so a database that cannot be written to is only logged.
     */
    public static function record(Order $order, string $platform, string $trigger, string $status, ?int $httpStatus = null, ?string $error = null, bool $retryable = false, ?int $eventTime = null): ?ConversionAttempt
    {
        try {
            $rows = ConversionAttempt::query()->where('order_id', $order->id)->where('platform', $platform);
            $number = (int) (clone $rows)->max('attempt') + 1;
            // The automatic tries used up, this one included when it is one: the next delay and the limit of three follow from it.
            $retriesDone = (clone $rows)->where('trigger', 'retry')->count() + ($trigger === 'retry' ? 1 : 0);
            $first = (clone $rows)->min('created_at');
            $next = null;

            if ($status === 'failed' && $retryable && $retriesDone < count(self::RETRY_DELAYS_MINUTES)) {
                $due = now()->addMinutes(self::RETRY_DELAYS_MINUTES[$retriesDone]);
                $stillFresh = $first === null || $due->lessThan(Carbon::parse($first)->addHours(self::WINDOW_HOURS));
                $next = $stillFresh ? $due : null;
            }

            return ConversionAttempt::query()->create([
                'order_id' => $order->id,
                'platform' => $platform,
                'attempt' => $number,
                'trigger' => $trigger,
                'status' => $status,
                'retryable' => $retryable,
                'http_status' => $httpStatus,
                'error' => $error === null ? null : mb_substr($error, 0, 500),
                'event_time' => $eventTime,
                'next_retry_at' => $next,
            ]);
        } catch (\Throwable $e) {
            Log::warning('A conversion attempt could not be written down.', ['order_id' => $order->id, 'platform' => $platform, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Where one platform stands for one order.
     *
     * state is one of: sent, retrying (failed, and the next automatic try is due), failed (nothing will try again by itself), skipped, none (no try yet).
     *
     * @return array{state: string, label: string, attempt: int, next: ?Carbon, error: ?string}
     */
    public static function state(Order $order, string $platform): array
    {
        $latest = self::latest($order, $platform);
        $name = self::PLATFORMS[$platform] ?? $platform;

        if ($latest === null) {
            return ['state' => 'none', 'label' => "{$name} Purchase: not sent", 'attempt' => 0, 'next' => null, 'error' => null];
        }

        $when = fn (Carbon $time): string => $time->timezone('Asia/Dhaka')->format('j M, g:i A');

        return match ($latest->status) {
            'sent' => ['state' => 'sent', 'label' => "{$name} Purchase: sent, ".$when($latest->created_at).($latest->attempt > 1 ? " (try {$latest->attempt})" : ''), 'attempt' => $latest->attempt, 'next' => null, 'error' => null],
            'skipped' => ['state' => 'skipped', 'label' => "{$name} Purchase: not sent ({$latest->error})", 'attempt' => $latest->attempt, 'next' => null, 'error' => $latest->error],
            default => $latest->retryable && $latest->next_retry_at !== null
                ? ['state' => 'retrying', 'label' => "{$name} Purchase: retrying ({$latest->error})".($latest->next_retry_at ? ', next try '.$when($latest->next_retry_at) : ''), 'attempt' => $latest->attempt, 'next' => $latest->next_retry_at, 'error' => $latest->error]
                : ['state' => 'failed', 'label' => "{$name} Purchase: failed ({$latest->error})".($latest->retryable ? ', gave up after '.$latest->attempt.' tries' : ', not tried again: this will not work by itself'), 'attempt' => $latest->attempt, 'next' => null, 'error' => $latest->error],
        };
    }

    /** True when a platform has failed and nothing is going to repeat it by itself: the "Send again" button is for these (and for a skipped one). */
    public static function canResend(Order $order): bool
    {
        if ($order->isLegacy() || ! ConversionEvents::allowed((bool) $order->marketing_consent)) {
            return false;
        }

        foreach (array_keys(self::PLATFORMS) as $platform) {
            if (in_array(self::state($order, $platform)['state'], ['failed', 'skipped'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * "Send again", from the order page. Only a platform that did not get the sale is tried (never one that did), a platform is not tried again
     * within a minute of its last try, and a second press while one is running does nothing. Runs now rather than after the response: the person is waiting.
     *
     * @return array{ok: bool, message: string}
     */
    public static function resend(Order $order): array
    {
        $key = "conversion-resend:{$order->id}";

        if (! Cache::add($key, 1, 60)) {
            return ['ok' => false, 'message' => 'A send for this order is already running. Wait a moment and look at the order again.'];
        }

        try {
            if (! self::canResend($order)) {
                return ['ok' => false, 'message' => 'Nothing to send again: the sale has been sent, or this order may not be reported.'];
            }

            if (blank($order->client_user_agent)) {
                return ['ok' => false, 'message' => 'The visitor\'s browser details were erased (they are kept 24 hours after a failed send), so Meta would refuse it now.'];
            }

            $sent = [];
            $waiting = false;

            foreach (self::PLATFORMS as $platform => $name) {
                $latest = self::latest($order, $platform);

                if ($latest === null || ! in_array($latest->status, ['failed', 'skipped'], true)) {
                    continue;
                }

                if ($latest->created_at->greaterThan(now()->subSeconds(self::RESEND_PAUSE_SECONDS))) {
                    $waiting = true;

                    continue;
                }

                // Takes it off the automatic list, so the scheduled task cannot also send it.
                ConversionAttempt::query()->whereKey($latest->id)->whereNotNull('next_retry_at')->update(['next_retry_at' => null]);
                self::run($platform, $order, $latest->event_time, 'manual');
                $sent[] = self::state($order, $platform)['label'];
            }

            if ($sent === []) {
                return ['ok' => false, 'message' => $waiting ? 'It was tried less than a minute ago. Wait a moment, then look at the order again.' : 'Nothing to send again.'];
            }

            return ['ok' => true, 'message' => implode('. ', $sent).'.'];
        } finally {
            Cache::forget($key);
        }
    }

    /** Sends one platform's Purchase now, in this request, as the given kind of try. The job never throws and writes the try down itself. */
    public static function run(string $platform, Order $order, ?int $eventTime, string $trigger): void
    {
        $job = $platform === 'meta'
            ? new SendMetaConversionEvent($order, null, null, $eventTime, $trigger)
            : new SendTikTokConversionEvent($order, null, null, $eventTime, $trigger);

        $job->handle();
    }

    /**
     * The scheduled task: every failed try that is due again. Stops when the time is up (the rest wait for the next run).
     *
     * @return array{retried: int, gave_up: int, left: int}
     */
    public static function retryDue(?float $until = null): array
    {
        $until ??= microtime(true) + 40;
        $summary = ['retried' => 0, 'gave_up' => 0, 'left' => 0];

        $due = ConversionAttempt::query()
            ->where('status', 'failed')->where('retryable', true)
            ->whereNotNull('next_retry_at')->where('next_retry_at', '<=', now())
            ->orderBy('next_retry_at')->get();

        foreach ($due as $row) {
            if (microtime(true) > $until) {
                $summary['left']++;

                continue;
            }

            // Taking the due time is the claim: a second run, or a person pressing Send again, finds it already taken.
            if (ConversionAttempt::query()->whereKey($row->id)->whereNotNull('next_retry_at')->update(['next_retry_at' => null]) !== 1) {
                continue;
            }

            $order = Order::query()->find($row->order_id);
            $first = ConversionAttempt::query()->where('order_id', $row->order_id)->where('platform', $row->platform)->min('created_at');

            // Never older than a day, never an order that may no longer be reported, never one that has since been sent.
            $tooOld = $first === null || Carbon::parse($first)->lessThan(now()->subHours(self::WINDOW_HOURS));
            $notAllowed = $order === null || $order->isLegacy() || ! ConversionEvents::allowed((bool) $order->marketing_consent);
            $latest = $order === null ? null : self::latest($order, $row->platform);

            if ($order === null || $latest === null || $latest->id !== $row->id) {
                continue;
            }

            if ($tooOld || $notAllowed) {
                $summary['gave_up']++;
                SystemEvents::record('capi_failed', ((self::PLATFORMS[$row->platform] ?? $row->platform)." Purchase for order #{$order->id}: not tried again ".($tooOld ? '(older than 24 hours)' : '(the visitor may no longer be reported)').". Last error: {$row->error}"));

                continue;
            }

            self::run($row->platform, $order, $row->event_time, 'retry');
            $summary['retried']++;
        }

        return $summary;
    }
}
