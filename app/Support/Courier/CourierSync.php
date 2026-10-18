<?php

namespace App\Support\Courier;

use App\Models\CourierSetting;
use App\Models\Order;
use App\Support\Dhaka;
use App\Support\System\SystemEvents;
use Illuminate\Support\Facades\Cache;

/**
 * The status check: the fallback that follows every booked parcel whether or not a webhook is arriving (and the only way for
 * Pathao, whose docs list no webhook). It runs from the scheduler every 15 minutes and is built never to be a burden:
 *
 *  - newest orders first, at most `per_run` of them, and it stops starting new checks when its seconds are used;
 *  - a parcel is not checked again for 15 minutes after a good check, and further away after each failure (up to six hours);
 *  - a courier gets at most `daily_cap` calls a day, and is left alone for the rest of a run after three failures in a row
 *    (an outage costs three calls, not forty);
 *  - a refused key (Steadfast 401) stops that courier at once and for good until the keys are saved again.
 *
 * It only reads. It is safe to run beside the webhook: both go through CourierStatusApplier.
 */
final class CourierSync
{
    /**
     * @return array{checked: int, changed: int, failed: int, stopped: list<string>}
     */
    public static function run(?int $limit = null): array
    {
        $settings = CourierSetting::current();
        $limit ??= (int) config('couriers.sync.per_run');
        $deadline = microtime(true) + (int) config('couriers.sync.seconds');
        $summary = ['checked' => 0, 'changed' => 0, 'failed' => 0, 'stopped' => []];
        $failuresInARow = [];
        $stopped = [];

        $orders = Order::query()
            ->whereNotNull('courier')
            ->whereNotNull('courier_booked_at')
            ->whereNull('courier_final_at')
            ->whereNull('courier_returned_at')
            ->where(fn ($query) => $query
                ->whereNotIn('status', ['delivered', 'cancelled'])
                // Told "delivered" by a webhook but not yet confirmed by a check: still waiting for the cash to be confirmed.
                ->orWhere(fn ($inner) => $inner->where('status', 'delivered')->where('payment_status', 'cod')->whereNull('cod_collected_at')->where('courier_booked_at', '>=', now()->subDays(14))))
            ->where(fn ($query) => $query->whereNull('courier_next_sync_at')->orWhere('courier_next_sync_at', '<=', now()))
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        foreach ($orders as $order) {
            $courier = (string) $order->courier;

            if (microtime(true) > $deadline) {
                $summary['stopped'][] = 'time';
                break;
            }

            if (isset($stopped[$courier]) || ! $settings->ready($courier)) {
                continue;
            }

            if (! self::underDailyCap($courier)) {
                $stopped[$courier] = true;
                $summary['stopped'][] = $courier.': daily cap';

                continue;
            }

            try {
                $outcome = self::check($settings, $order, $courier);
                $failuresInARow[$courier] = 0;
                $summary['checked']++;

                if (in_array($outcome, ['applied', 'confirmed'], true)) {
                    $summary['changed']++;
                }
            } catch (CourierException $e) {
                CourierStatusApplier::checkFailed($order, $e);
                $summary['failed']++;

                // A refused login stops the courier outright; a few failures in a row mean an outage.
                $failuresInARow[$courier] = ($failuresInARow[$courier] ?? 0) + 1;

                if ($e->kind === CourierException::AUTH || $failuresInARow[$courier] >= (int) config('couriers.sync.stop_after_failures')) {
                    $stopped[$courier] = true;
                    $summary['stopped'][] = $courier.($e->kind === CourierException::AUTH ? ': keys refused' : ': failing');
                }
            }
        }

        SystemEvents::mark('courier_sync', "{$summary['checked']} checked, {$summary['changed']} changed, {$summary['failed']} failed".($summary['stopped'] ? ' ('.implode(', ', $summary['stopped']).')' : ''), $summary['failed'] > 0 ? 'warn' : 'ok');

        return $summary;
    }

    private static function check(CourierSetting $settings, Order $order, string $courier): string
    {
        if ($courier === CourierSetting::STEADFAST) {
            $status = (new SteadfastClient($settings))->statusByInvoice((string) ($order->courier_invoice ?: $order->id));

            return CourierStatusApplier::apply($order, $courier, $status, 'poll');
        }

        $info = (new PathaoClient($settings))->orderInfo((string) $order->courier_consignment_id);

        return CourierStatusApplier::apply($order, $courier, $info['slug'], 'poll', $info['text']);
    }

    private static function underDailyCap(string $courier): bool
    {
        $key = 'courier-sync:'.$courier.':'.Dhaka::day();
        Cache::add($key, 0, now()->addDay());

        return (int) Cache::increment($key) <= (int) config('couriers.sync.daily_cap');
    }
}
