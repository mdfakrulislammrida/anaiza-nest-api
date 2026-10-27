<?php

namespace App\Support\Reviews;

use App\Models\EmailOptOut;
use App\Models\Order;
use App\Models\ProductReview;
use App\Models\ReviewRequestSetting;
use App\Support\Recovery\RecoveryEmails;
use App\Support\System\TaskBudget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Asks for reviews. Orders delivered long enough ago, with no request yet, get one email (and later, if switched on, one
 * gentle reminder); anyone who has already reviewed every piece, or has opted out, is skipped. Only orders delivered after
 * the requests were switched on are ever asked.
 */
final class ReviewRequests
{
    public const BATCH = 40;

    /**
     * @return array{sent: int, reminded: int, skipped: int}
     */
    public static function run(?Carbon $now = null, ?float $until = null): array
    {
        $now ??= now();
        // Emails are sent one by one: stop when the run's time is up and carry on at the next run (nothing is lost; a sent request is marked).
        $until ??= TaskBudget::until();
        $settings = ReviewRequestSetting::current();
        $result = ['sent' => 0, 'reminded' => 0, 'skipped' => 0];

        if (! $settings->enabled || ! $settings->email_enabled) {
            return $result;
        }

        $due = self::due($settings, $now)->whereNull('review_request_sent_at')->whereNull('review_request_status')->limit(self::BATCH)->get();

        foreach ($due as $order) {
            if (microtime(true) > $until) {
                return $result;
            }

            $outcome = self::requestFor($order, false, $now);
            $result[$outcome === 'sent' ? 'sent' : 'skipped']++;
        }

        if ($settings->reminder_enabled) {
            $reminders = Order::query()
                ->notLegacy()
                ->where('status', 'delivered')
                ->whereNotNull('review_request_sent_at')
                ->whereNull('review_reminder_sent_at')
                ->where('review_request_status', 'sent')
                ->where('review_request_sent_at', '<=', $now->copy()->subDays(max(1, $settings->reminder_days)))
                ->where('review_request_sent_at', '>=', $now->copy()->subDays(ReviewLinks::VALID_DAYS))
                ->with(['customer', 'items.product'])
                ->limit(self::BATCH)
                ->get();

            foreach ($reminders as $order) {
                if (microtime(true) > $until) {
                    return $result;
                }

                $outcome = self::requestFor($order, true, $now);
                $result[$outcome === 'sent' ? 'reminded' : 'skipped']++;
            }
        }

        return $result;
    }

    /**
     * Delivered orders that are old enough to be asked (the "Review request due" list), whichever way they are asked.
     *
     * @return Builder<Order>
     */
    public static function due(ReviewRequestSetting $settings, ?Carbon $now = null): Builder
    {
        $now ??= now();

        return self::scopeDue(Order::query(), $settings, $now)->with(['customer', 'items.product']);
    }

    /**
     * The "old enough to ask" conditions, for any order query (the scheduler's, and the Orders list filter).
     *
     * @param  Builder<Order>  $query
     * @return Builder<Order>
     */
    public static function scopeDue(Builder $query, ReviewRequestSetting $settings, ?Carbon $now = null): Builder
    {
        $now ??= now();

        return $query
            ->notLegacy()
            ->where('status', 'delivered')
            ->where('payment_status', '!=', 'failed')
            ->whereNotNull('delivered_at')
            ->where('delivered_at', '<=', $now->copy()->subDays(max(0, $settings->days_after_delivery)))
            ->when($settings->enabled_at, fn (Builder $q) => $q->where('delivered_at', '>=', $settings->enabled_at));
    }

    /**
     * Due and not yet asked, by email or by WhatsApp.
     *
     * @param  Builder<Order>  $query
     * @return Builder<Order>
     */
    public static function scopeAwaiting(Builder $query, ReviewRequestSetting $settings, ?Carbon $now = null): Builder
    {
        return self::scopeDue($query, $settings, $now)
            ->whereNull('review_request_sent_at')
            ->whereNull('review_whatsapp_clicked_at')
            ->where(fn (Builder $q) => $q->whereNull('review_request_status')->orWhere('review_request_status', 'no_email'));
    }

    /** The lines of this order nobody has reviewed yet. */
    public static function unreviewedLines(Order $order): int
    {
        $reviewed = ProductReview::query()->whereIn('order_item_id', $order->items->pluck('id'))->pluck('order_item_id');

        return $order->items->reject(fn ($item) => $reviewed->contains($item->id))->count();
    }

    private static function requestFor(Order $order, bool $reminder, Carbon $now): string
    {
        $order->loadMissing(['customer', 'items.product']);
        $email = $order->recipient()['email'];

        if (blank($email)) {
            $order->forceFill(['review_request_status' => 'no_email'])->saveQuietly();

            return 'skipped';
        }

        if (EmailOptOut::covers($email, $order->recipient()['phone'])) {
            $order->forceFill(['review_request_status' => 'opted_out'])->saveQuietly();

            return 'skipped';
        }

        if (self::unreviewedLines($order) === 0) {
            if (! $reminder) {
                $order->forceFill(['review_request_status' => 'reviewed'])->saveQuietly();
            }

            return 'skipped';
        }

        // Claim first, so two runs can never send the same request twice.
        $column = $reminder ? 'review_reminder_sent_at' : 'review_request_sent_at';
        $claimed = Order::query()->whereKey($order->id)->whereNull($column)->update([
            $column => $now,
            ...($reminder ? [] : ['review_request_status' => 'sent']),
        ]);

        if ($claimed === 0) {
            return 'skipped';
        }

        $order->forceFill([$column => $now, ...($reminder ? [] : ['review_request_status' => 'sent'])])->syncOriginal();

        try {
            RecoveryEmails::sendReview($order, $reminder);
        } catch (\Throwable $e) {
            Log::error('Review request email failed.', ['order_id' => $order->id, 'error' => $e->getMessage()]);
            Order::query()->whereKey($order->id)->update([$column => null, ...($reminder ? [] : ['review_request_status' => null])]);

            return 'failed';
        }

        return 'sent';
    }
}
