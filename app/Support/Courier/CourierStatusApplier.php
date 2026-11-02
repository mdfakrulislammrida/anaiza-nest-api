<?php

namespace App\Support\Courier;

use App\Filament\Resources\OrderResource;
use App\Models\CourierEvent;
use App\Models\CourierSetting;
use App\Models\CourierStatusMap;
use App\Models\Order;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Where a courier's report of a parcel changes the order. Polling and the webhook both come through here, so the rules are
 * the same whichever brought the news, and running both side by side is safe:
 *
 *  - The status map decides what a status means (Settings > Courier statuses). A status we have never seen is stored as it
 *    came and shown as "Unknown status" until someone maps it.
 *  - A mapping that is not armed (the ready-made Pathao rows, until the owner presses "Arm Pathao statuses") shows its meaning and changes
 *    nothing. Once armed, a parcel whose status was already stored is worked out on the next check (courier_effect_status says which).
 *  - Never backwards: a report with a lower rank than the one on the order is kept as an event and ignored. Once a parcel is
 *    final (delivered, partly delivered, cancelled, as the map says) nothing more changes it.
 *  - "Delivered" sets the order Delivered (which sends the delivered email once and starts the review timer), and only for
 *    a status the map marks final: a rider's own word (Steadfast's *_approval_pending) never delivers anything.
 *  - Returned or cancelled by the courier flags the order and tells the owner. Stock and refunds are never touched.
 *  - "Update order status from courier automatically" (Settings > Courier) off: the report is kept and shown, and nothing on the order
 *    changes (no status, no cash, no end of checking).
 *  - Every change the courier makes to an order is a line in its Courier history that names where it came from ("Steadfast webhook",
 *    "couriers:sync", "Sync now") and the courier's own word for the status.
 *  - Customers hear about a change (the existing shipped and delivered emails, once each) only when the news is fresh: a status the shop has
 *    known for more than 24 hours that only now changes an order (a catch-up) sends no email, and starts no review request, unless the
 *    owner asked for it (couriers:preview-effects --apply --notify). The shop sends no SMS at all.
 *  - Cash collected is recorded only when a status check (polling), not a webhook alone, confirms a final status that the map
 *    says collected the cash. A webhook that says delivered leaves the order waiting for that confirmation.
 */
final class CourierStatusApplier
{
    /** How long after a check the next one may be (never inside Steadfast's 60 second cache). */
    public const POLL_MINUTES = 15;

    /** A status known for longer than this is old news: a change it causes tells no customer. */
    public const FRESH_HOURS = 24;

    /**
     * @param  bool  $forceArmed  act on the mapping even if it is not armed (only the owner's own --apply does this)
     * @param  bool  $notifyOld  still send the customer emails for old news (only the owner's own --notify does this)
     * @return string applied | unchanged | confirmed | recorded | ignored_preorder | ignored_backwards | ignored_final | ignored_empty
     */
    public static function apply(Order $order, string $courier, string $rawStatus, string $source, ?string $label = null, bool $forceArmed = false, bool $notifyOld = false): string
    {
        $raw = mb_substr(trim($rawStatus), 0, 120);

        if ($raw === '') {
            return 'ignored_empty';
        }

        return DB::transaction(function () use ($order, $courier, $raw, $source, $label, $forceArmed, $notifyOld): string {
            $row = Order::query()->lockForUpdate()->find($order->id);

            if ($row === null) {
                return 'ignored_missing';
            }

            // A Pre-order is not on its way yet: whatever a courier says about it changes nothing, and the report is kept in the order's history.
            if ($row->status === 'preorder') {
                self::event($row, $courier, $source, 'status_ignored', $raw, self::sourceLabel($courier, $source).' reported "'.$raw.'", but the order is a Pre-order, so nothing was changed.');

                return 'ignored_preorder';
            }

            $map = CourierStatusMap::noteSeen($courier, $raw, $label);
            $confirmed = $source !== 'webhook';
            $name = self::sourceLabel($courier, $source);
            $automatic = (bool) CourierSetting::current()->status_sync_enabled;
            $live = $forceArmed || $map->armed;
            // What the status does to the order right now: its mapping, or nothing while it is not armed.
            $act = $live ? $map : $map->inert();
            $key = CourierStatusMap::normalise($raw);

            // Final is final.
            if ($row->courier_final_at !== null) {
                if ($key !== CourierStatusMap::normalise((string) $row->courier_status)) {
                    self::event($row, $courier, $source, 'status_ignored', $raw, $name.' reported '.$map->stage.' after the parcel was final; ignored.');
                }

                return 'ignored_final';
            }

            // The same news again (or news we already hold that has only now been switched on).
            if ($key === CourierStatusMap::normalise((string) $row->courier_status)) {
                $row->forceFill(self::checked($row))->saveQuietly();
                $changed = false;

                if ($automatic && $live && $row->courier_effect_status !== $key) {
                    $changed = self::effects($row, $courier, $act, $source, $raw, self::oldNews($row, $raw) && ! $notifyOld, quiet: true);
                    $row->forceFill(['courier_effect_status' => $key])->saveQuietly();
                }

                if ($automatic && $act->is_final && $confirmed) {
                    self::finalise($row, $courier, $act, $source);

                    return 'confirmed';
                }

                return $changed ? 'applied' : 'unchanged';
            }

            // Never backwards.
            if ($map->rank !== null && $row->courier_status_rank !== null && $map->rank < $row->courier_status_rank) {
                $row->forceFill(self::checked($row))->saveQuietly();
                self::event($row, $courier, $source, 'status_ignored', $raw, $name.' reported '.$map->stage.', which is earlier than '.$row->courier_status_text.'; ignored.');

                return 'ignored_backwards';
            }

            // A final status told by a webhook still has to be confirmed by a status check, so the check comes soon, not in 15 minutes.
            $soon = ! $confirmed && $act->is_final ? ['courier_next_sync_at' => now()->addMinutes(2)] : [];

            $row->forceFill($soon + self::checked($row) + [
                'courier_status' => $raw,
                'courier_status_text' => $map->stage,
                'courier_status_rank' => $map->rank ?? $row->courier_status_rank,
            ])->saveQuietly();

            self::event($row, $courier, $source, 'status', $raw, $name.' reported "'.$raw.'" ('.$map->stage.').');

            if (! $automatic) {
                self::event($row, $courier, $source, 'status_recorded', $raw, 'Automatic order updates are switched off (Settings > Courier), so the order itself was not changed.');

                return 'recorded';
            }

            if (! $live) {
                self::event($row, $courier, $source, 'status_recorded', $raw, 'This status is not switched on yet (Settings > Courier statuses > Arm), so the order itself was not changed.');

                return 'recorded';
            }

            self::effects($row, $courier, $act, $source, $raw, false);
            $row->forceFill(['courier_effect_status' => $key])->saveQuietly();

            if ($act->is_final && $confirmed) {
                self::finalise($row, $courier, $act, $source);
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
        $map = CourierStatusMap::lookup($courier, $status);

        return ['stage' => $map?->stage, 'rank' => $map?->rank];
    }

    /**
     * What a stored courier status would do to an order if its mapping acted now. Nothing is written. This is the one place that knows what
     * an effect may do; applying it and the preview both read it.
     *
     * @return array{to: ?string, blocked: bool, flag: bool, cash: bool}
     */
    public static function plan(Order $order, CourierStatusMap $map): array
    {
        $move = self::MOVES[$map->effect] ?? null;
        $to = null;
        $blocked = false;

        // Delivered needs a status the map marks final, never a rider's word.
        if ($move !== null && ($map->effect !== 'delivered' || $map->is_final) && $order->status !== $move['to']) {
            in_array($order->status, $move['from'], true) ? $to = $move['to'] : $blocked = true;
        }

        $statusAfter = $to ?? $order->status;
        $cash = $map->is_final && $map->marks_cod_collected && $order->payment_status === 'cod' && $order->cod_collected_at === null
            && ! in_array($statusAfter, ['cancelled', 'returned'], true);

        return ['to' => $to, 'blocked' => $blocked, 'flag' => $map->effect === 'returned' && $order->courier_returned_at === null, 'cash' => $cash];
    }

    /** True when the shop has held this status on the order for longer than a day: whatever it changes now is a catch-up, not news. */
    public static function oldNews(Order $order, string $raw): bool
    {
        $since = CourierEvent::query()->where('order_id', $order->id)->where('event_type', 'status')->where('status', $raw)->latest('id')->value('created_at')
            ?? $order->courier_booked_at;

        return $since !== null && Carbon::parse($since)->lt(now()->subHours(self::FRESH_HOURS));
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

    /** The order status each effect may move an order to, and the statuses it may move it from. Anything else (delivered, cancelled, returned) is left alone. */
    private const MOVES = [
        'shipped' => ['to' => 'shipped', 'from' => ['pending', 'processing']],
        'delivered' => ['to' => 'delivered', 'from' => ['pending', 'processing', 'shipped']],
    ];

    /**
     * @param  bool  $mute  change the order without telling the customer (old news)
     * @param  bool  $quiet  say nothing in the history when there is nothing to do (a repeat of news already held)
     * @return bool whether the order was changed
     */
    private static function effects(Order $order, string $courier, CourierStatusMap $map, string $source, string $raw, bool $mute, bool $quiet = false): bool
    {
        $plan = self::plan($order, $map);
        $by = self::sourceLabel($courier, $source);
        $nice = fn (string $status): string => OrderResource::STATUSES[$status] ?? $status;
        $changed = false;

        if ($plan['to'] !== null) {
            $was = $order->status;
            $order->quietStatusChange = $mute;

            try {
                $order->update(['status' => $plan['to']]);
            } finally {
                $order->quietStatusChange = false;
            }

            $changed = true;
            self::event($order, $courier, $source, 'order_status', $raw, $by.' moved the order from '.$nice($was).' to '.$nice($plan['to']).' (courier status "'.$raw.'").'.($mute ? ' The report is more than '.self::FRESH_HOURS.' hours old, so no customer email was sent.' : ''));

            // A delivery found late must not start a review request out of the blue.
            if ($mute && $plan['to'] === 'delivered' && $order->review_request_status === null) {
                $order->forceFill(['review_request_status' => 'catch_up'])->saveQuietly();
            }
        } elseif ($plan['blocked'] && ! $quiet) {
            self::event($order, $courier, $source, 'status_ignored', $raw, $by.' reported "'.$raw.'", but the order is '.$nice($order->status).', so it was left as it is.');
        }

        if ($plan['flag']) {
            $order->forceFill(['courier_returned_at' => now()])->saveQuietly();
            $changed = true;
        }

        if (in_array($map->effect, ['returned', 'partial'], true)) {
            CourierAlerts::parcelProblem($order, $courier, $map);
        }

        return $changed;
    }

    private static function finalise(Order $order, string $courier, CourierStatusMap $map, string $source): void
    {
        $fill = ['courier_final_at' => now()];

        // An order a person cancelled or marked returned keeps its money as it is, whatever the courier says afterwards.
        $closed = in_array($order->status, ['cancelled', 'returned'], true);

        if ($map->marks_cod_collected && $closed) {
            self::event($order, $courier, $source, 'status_ignored', $order->courier_status, 'The order is '.$order->status.', so no cash was recorded as collected.');
        } elseif ($map->marks_cod_collected && $order->payment_status === 'cod' && $order->cod_collected_at === null) {
            $amount = (int) ($order->cod_amount_sent ?? $order->total);
            $fill += ['cod_collected_at' => now(), 'cod_collected_amount' => $amount];

            self::event($order, $courier, $source, 'cod_collected', $order->courier_status, 'Cash collected, ৳'.number_format($amount).', recorded: '.CourierSettingNames::label($courier).' confirmed delivery.');
        }

        $order->forceFill($fill)->saveQuietly();
    }

    /** "Steadfast webhook", "couriers:sync" or "Sync now": where a report came from, for the order's history. */
    public static function sourceLabel(string $courier, string $source): string
    {
        return match ($source) {
            'webhook' => CourierSettingNames::label($courier).' webhook',
            'poll' => 'couriers:sync',
            'sync_now' => 'Sync now',
            'preview' => 'couriers:preview-effects',
            default => CourierSettingNames::label($courier),
        };
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
