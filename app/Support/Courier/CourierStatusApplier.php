<?php

namespace App\Support\Courier;

use App\Models\CourierEvent;
use App\Models\CourierStatusMap;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

/**
 * Where a courier's report of a parcel changes the order. Polling and the webhook both come through here, so the rules are
 * the same whichever brought the news, and running both side by side is safe:
 *
 *  - The status map decides what a status means (Settings > Courier statuses). A status we have never seen is stored as it
 *    came and shown as "Unknown status" until someone maps it.
 *  - Never backwards: a report with a lower rank than the one on the order is kept as an event and ignored. Once a parcel is
 *    final (delivered, partly delivered, cancelled, as the map says) nothing more changes it.
 *  - "Delivered" sets the order Delivered (which sends the delivered email once and starts the review timer), and only for
 *    a status the map marks final: a rider's own word (Steadfast's *_approval_pending) never delivers anything.
 *  - Returned or cancelled by the courier flags the order and tells the owner. Stock and refunds are never touched.
 *  - Cash collected is recorded only when a status check (polling), not a webhook alone, confirms a final status that the map
 *    says collected the cash. A webhook that says delivered leaves the order waiting for that confirmation.
 */
final class CourierStatusApplier
{
    /** How long after a check the next one may be (never inside Steadfast's 60 second cache). */
    public const POLL_MINUTES = 15;

    /**
     * @return string applied | unchanged | confirmed | ignored_backwards | ignored_final | ignored_empty
     */
    public static function apply(Order $order, string $courier, string $rawStatus, string $source, ?string $label = null): string
    {
        $raw = mb_substr(trim($rawStatus), 0, 120);

        if ($raw === '') {
            return 'ignored_empty';
        }

        return DB::transaction(function () use ($order, $courier, $raw, $source, $label): string {
            $row = Order::query()->lockForUpdate()->find($order->id);

            if ($row === null) {
                return 'ignored_missing';
            }

            $map = CourierStatusMap::noteSeen($courier, $raw, $label);
            $confirmed = $source !== 'webhook';
            $name = CourierSettingNames::label($courier);

            // Final is final.
            if ($row->courier_final_at !== null) {
                if ($raw !== $row->courier_status) {
                    self::event($row, $courier, $source, 'status_ignored', $raw, $name.' reported '.$map->stage.' after the parcel was final; ignored.');
                }

                return 'ignored_final';
            }

            if ($raw === $row->courier_status) {
                $row->forceFill(self::checked($row))->saveQuietly();

                if ($map->is_final && $confirmed) {
                    self::finalise($row, $courier, $map, $source);

                    return 'confirmed';
                }

                return 'unchanged';
            }

            // Never backwards.
            if ($map->rank !== null && $row->courier_status_rank !== null && $map->rank < $row->courier_status_rank) {
                $row->forceFill(self::checked($row))->saveQuietly();
                self::event($row, $courier, $source, 'status_ignored', $raw, $name.' reported '.$map->stage.', which is earlier than '.$row->courier_status_text.'; ignored.');

                return 'ignored_backwards';
            }

            // A final status told by a webhook still has to be confirmed by a status check, so the check comes soon, not in 15 minutes.
            $soon = ! $confirmed && $map->is_final ? ['courier_next_sync_at' => now()->addMinutes(2)] : [];

            $row->forceFill($soon + self::checked($row) + [
                'courier_status' => $raw,
                'courier_status_text' => $map->stage,
                'courier_status_rank' => $map->rank ?? $row->courier_status_rank,
            ])->saveQuietly();

            self::event($row, $courier, $source, 'status', $raw, $name.': '.$map->stage.'.');

            self::effects($row, $courier, $map);

            if ($map->is_final && $confirmed) {
                self::finalise($row, $courier, $map, $source);
            }

            return 'applied';
        });
    }

    /**
     * What the map says about a status, without counting a sighting (for the first status a booking comes back with).
     *
     * @return array{stage: ?string, rank: ?int}
     */
    public static function describe(string $courier, ?string $status): array
    {
        $map = $status === null ? null : CourierStatusMap::query()->where('courier', $courier)->where('courier_status', $status)->first();

        return ['stage' => $map?->stage, 'rank' => $map?->rank];
    }

    /** A check that failed: try again later, further away each time (15 minutes, doubling, at most six hours). */
    public static function checkFailed(Order $order, CourierException $e): void
    {
        $failures = (int) $order->courier_sync_failures + 1;
        $minutes = min(self::POLL_MINUTES * (2 ** min($failures, 6)), 360);

        Order::query()->whereKey($order->id)->update([
            'courier_sync_failures' => $failures,
            'courier_next_sync_at' => now()->addMinutes($minutes),
            'courier_last_error' => mb_substr('Status check: '.$e->getMessage(), 0, 480),
            'courier_last_error_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private static function checked(Order $order): array
    {
        $clearError = str_starts_with((string) $order->courier_last_error, 'Status check');

        return [
            'courier_synced_at' => now(),
            'courier_sync_failures' => 0,
            'courier_next_sync_at' => now()->addMinutes(self::POLL_MINUTES),
        ] + ($clearError ? ['courier_last_error' => null, 'courier_last_error_at' => null] : []);
    }

    private static function effects(Order $order, string $courier, CourierStatusMap $map): void
    {
        // Delivered: only a status the map marks final, never a rider's word. Moves forward only: pending, processing or shipped.
        if ($map->effect === 'delivered' && $map->is_final && in_array($order->status, ['pending', 'processing', 'shipped'], true)) {
            $order->update(['status' => 'delivered']);
        }

        if ($map->effect === 'returned' && $order->courier_returned_at === null) {
            $order->forceFill(['courier_returned_at' => now()])->saveQuietly();
        }

        if (in_array($map->effect, ['returned', 'partial'], true)) {
            CourierAlerts::parcelProblem($order, $courier, $map);
        }
    }

    private static function finalise(Order $order, string $courier, CourierStatusMap $map, string $source): void
    {
        $fill = ['courier_final_at' => now()];

        if ($map->marks_cod_collected && $order->payment_status === 'cod' && $order->cod_collected_at === null) {
            $amount = (int) ($order->cod_amount_sent ?? $order->total);
            $fill += ['cod_collected_at' => now(), 'cod_collected_amount' => $amount];

            self::event($order, $courier, $source, 'cod_collected', $order->courier_status, 'Cash collected, ৳'.number_format($amount).', recorded: '.CourierSettingNames::label($courier).' confirmed delivery.');
        }

        $order->forceFill($fill)->saveQuietly();
    }

    private static function event(Order $order, string $courier, string $source, string $type, ?string $status, string $summary): void
    {
        CourierEvent::query()->create([
            'order_id' => $order->id,
            'courier' => $courier,
            'source' => $source,
            'event_type' => $type,
            'status' => $status,
            'summary' => mb_substr($summary, 0, 480),
        ]);
    }
}
