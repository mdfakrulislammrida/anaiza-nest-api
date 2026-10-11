<?php

namespace App\Support\Recovery;

use App\Models\AbandonedCheckout;
use App\Models\CartRecoverySetting;
use App\Models\CheckoutDailyCount;
use App\Models\Customer;
use App\Models\EmailOptOut;
use App\Models\Order;
use App\Support\Coupons\CouponService;
use App\Support\Dhaka;
use App\Support\Email\TemplateDefinitions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * One pass of the scheduler over the open checkouts. Each step goes out at most once per checkout, never inside the quiet
 * hours (it waits), never past the day's cap, never to someone who ordered or unsubscribed.
 */
final class CartRecovery
{
    public const SENT_COUNTER = 'recovery_sent';

    /**
     * @return array{sent: int, skipped: int, waiting: string|null}
     */
    public static function run(?Carbon $now = null): array
    {
        $now ??= now();
        $settings = CartRecoverySetting::current();
        $result = ['sent' => 0, 'skipped' => 0, 'waiting' => null];

        if (! $settings->enabled) {
            $result['waiting'] = 'off';

            return $result;
        }

        if (Dhaka::isQuiet($settings->quiet_start, $settings->quiet_end, $now)) {
            $result['waiting'] = 'quiet_hours';

            return $result;
        }

        $sentToday = (int) DB::table('checkout_daily_counts')->where('day', Dhaka::day($now))->where('stage', self::SENT_COUNTER)->value('visitors');
        $room = max(0, $settings->daily_cap - $sentToday);

        if ($room === 0) {
            $result['waiting'] = 'daily_cap';

            return $result;
        }

        $oldestActivity = $now->copy()->subDays(max(1, $settings->max_age_days));
        $shortestDelay = min(
            $settings->step1_enabled ? $settings->step1_delay_minutes : PHP_INT_MAX,
            $settings->step2_enabled ? $settings->step2_delay_minutes : PHP_INT_MAX,
        );

        if ($shortestDelay === PHP_INT_MAX) {
            return $result;
        }

        $candidates = AbandonedCheckout::query()
            ->where('status', AbandonedCheckout::STATUS_OPEN)
            ->whereNull('order_id')
            ->whereNotNull('email')
            ->where('unsubscribed', false)
            ->where('cart_total', '>', 0)
            ->where('cart_total', '>=', $settings->min_cart_value)
            ->where('messages_count', '<', max(1, $settings->max_messages))
            ->where('last_activity_at', '>=', $oldestActivity)
            ->where('last_activity_at', '<=', $now->copy()->subMinutes($shortestDelay))
            ->when($settings->enabled_at, fn ($q) => $q->where('last_activity_at', '>=', $settings->enabled_at))
            ->orderBy('last_activity_at')
            ->limit(200)
            ->get();

        foreach ($candidates as $checkout) {
            if ($room <= 0) {
                $result['waiting'] = 'daily_cap';
                break;
            }

            $step = self::dueStep($checkout, $settings, $now);

            if ($step === null) {
                continue;
            }

            if (EmailOptOut::covers($checkout->email, $checkout->phone)) {
                $checkout->forceFill(['unsubscribed' => true, 'status' => AbandonedCheckout::STATUS_UNSUBSCRIBED])->save();
                $result['skipped']++;

                continue;
            }

            if (self::alreadyOrdered($checkout)) {
                $checkout->forceFill(['stage' => 'ordered', 'status' => AbandonedCheckout::STATUS_ORDERED, 'ordered_at' => $now])->save();
                $result['skipped']++;

                continue;
            }

            if (self::send($checkout, $step, $settings, $now)) {
                $result['sent']++;
                $room--;
                CheckoutDailyCount::bump(self::SENT_COUNTER);
            } else {
                $result['skipped']++;
            }
        }

        return $result;
    }

    /** The step that is due for this checkout now (1 or 2), or null when none is. */
    public static function dueStep(AbandonedCheckout $checkout, CartRecoverySetting $settings, Carbon $now): ?int
    {
        $idle = $checkout->last_activity_at->diffInMinutes($now, false);

        if ($settings->step1_enabled && ! $checkout->sentStep(1)) {
            return $idle >= $settings->step1_delay_minutes ? 1 : null;
        }

        if ($settings->step2_enabled && ! $checkout->sentStep(2) && $idle >= $settings->step2_delay_minutes) {
            return 2;
        }

        return null;
    }

    /** Someone with the same email or phone has placed an order since this cart was started. */
    private static function alreadyOrdered(AbandonedCheckout $checkout): bool
    {
        $customers = Customer::query()
            ->where(function ($q) use ($checkout) {
                if ($checkout->email) {
                    $q->orWhere('email', $checkout->email);
                }
                if ($checkout->phone) {
                    $q->orWhere('phone', $checkout->phone);
                }
            })
            ->pluck('id');

        return $customers->isNotEmpty() && Order::query()
            ->whereIn('customer_id', $customers)
            ->where('created_at', '>=', $checkout->first_activity_at ?? $checkout->created_at)
            ->where('status', '!=', 'cancelled')
            ->exists();
    }

    /**
     * Claims the step (so it can never go twice), makes the coupon when the step has one, and sends. A send that fails
     * gives the step back, so the next run tries again.
     */
    public static function send(AbandonedCheckout $checkout, int $step, CartRecoverySetting $settings, Carbon $now): bool
    {
        $claimed = DB::transaction(function () use ($checkout, $step, $now): bool {
            $row = AbandonedCheckout::query()->lockForUpdate()->find($checkout->id);

            if ($row === null || ! $row->isOpen() || $row->sentStep($step) || $row->order_id !== null) {
                return false;
            }

            $messages = $row->messages ?? [];
            $messages["step{$step}"] = $now->toIso8601String();
            $row->forceFill(['messages' => $messages, 'messages_count' => $row->messages_count + 1, 'last_message_at' => $now])->save();
            $checkout->setRawAttributes($row->getAttributes(), true);

            return true;
        });

        if (! $claimed) {
            return false;
        }

        try {
            $coupon = null;
            $template = TemplateDefinitions::CART_REMINDER;

            if ($step === 2 && $settings->step2_coupon_enabled) {
                $coupon = $checkout->coupon ?? CouponService::createFor(
                    $checkout->email, $checkout->phone, $settings->coupon_type, $settings->coupon_amount,
                    $settings->coupon_min_order, $settings->coupon_valid_hours, $checkout->id, $now,
                );
                $checkout->forceFill(['coupon_id' => $coupon->id])->save();
                $template = TemplateDefinitions::CART_OFFER;
            }

            RecoveryEmails::sendCart($checkout, $template, $coupon);

            return true;
        } catch (\Throwable $e) {
            Log::error('Cart recovery email failed.', ['checkout_id' => $checkout->id, 'step' => $step, 'error' => $e->getMessage()]);

            $row = AbandonedCheckout::query()->find($checkout->id);
            $messages = $row?->messages ?? [];
            unset($messages["step{$step}"]);
            $row?->forceFill(['messages' => $messages ?: null, 'messages_count' => max(0, ($row->messages_count ?? 1) - 1)])->save();

            return false;
        }
    }

    /**
     * Old rows are deleted after the retention period; open ones nobody is going to be reminded of any more are marked
     * expired.
     *
     * @return array{expired: int, deleted: int}
     */
    public static function tidy(?Carbon $now = null): array
    {
        $now ??= now();
        $settings = CartRecoverySetting::current();

        $expired = AbandonedCheckout::query()
            ->where('status', AbandonedCheckout::STATUS_OPEN)
            ->where('last_activity_at', '<', $now->copy()->subDays(max(1, $settings->max_age_days)))
            ->update(['status' => AbandonedCheckout::STATUS_EXPIRED]);

        $deleted = AbandonedCheckout::query()
            ->where(function ($q) use ($now, $settings) {
                $q->where('last_activity_at', '<', $now->copy()->subDays(max(1, $settings->retention_days)))
                    ->orWhere(fn ($q) => $q->whereNull('last_activity_at')->where('created_at', '<', $now->copy()->subDays(max(1, $settings->retention_days))));
            })
            ->delete();

        DB::table('checkout_daily_counts')
            ->where('day', '<', Dhaka::day($now->copy()->subDays(max(1, $settings->retention_days))))
            ->delete();

        return ['expired' => $expired, 'deleted' => $deleted];
    }
}
