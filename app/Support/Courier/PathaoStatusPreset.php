<?php

namespace App\Support\Courier;

use App\Models\CourierSetting;
use App\Models\CourierStatusMap;

/**
 * The Pathao statuses the shop has actually seen, with a sensible meaning for each (Settings > Courier statuses).
 *
 * Seeding is safe to repeat and never changes an order:
 *  - a row the owner has already edited is left exactly as it is;
 *  - a row that is still the untouched "Unknown status" is filled in, and a missing one is added, both NOT armed: they show their meaning but
 *    do nothing to an order until "Arm Pathao statuses" is pressed (see CourierStatusMap).
 *
 * Only statuses seen in this shop's own data are listed. Any other Pathao status stays "Unknown status" until it is mapped by hand; none is guessed.
 * Ranks leave room around the Steadfast ones: 10 booked, 100 delivered. The four "in transit" statuses share a rank, because a parcel can
 * pass through several hubs and each report is simply the latest.
 */
final class PathaoStatusPreset
{
    /** @return list<array{status: string, stage: string, effect: string, final: bool, cod: bool, rank: int}> */
    public static function rows(): array
    {
        $row = fn (string $status, string $stage, string $effect, int $rank, bool $final = false, bool $cod = false): array => [
            'status' => $status, 'stage' => $stage, 'effect' => $effect, 'final' => $final, 'cod' => $cod, 'rank' => $rank,
        ];

        return [
            $row('pending', 'Booked, waiting for pickup', 'none', 10),
            $row('Pickup_Requested', 'Booked, waiting for pickup', 'none', 10),
            $row('Assigned_for_Pickup', 'Booked, waiting for pickup', 'none', 15),
            $row('On_the_way_to_Sorting HUB', 'In transit', 'shipped', 30),
            $row('At_the_Sorting_HUB', 'In transit', 'shipped', 30),
            $row('In_Transit', 'In transit', 'shipped', 30),
            $row('Received_at_Last_Mile_HUB', 'In transit', 'shipped', 30),
            $row('Assigned_for_Delivery', 'Out for delivery', 'none', 45),
            $row('Delivered', 'Delivered', 'delivered', 100, true, true),
        ];
    }

    /**
     * @return array{added: int, filled: int, kept: int}
     */
    public static function seed(): array
    {
        $summary = ['added' => 0, 'filled' => 0, 'kept' => 0];

        foreach (self::rows() as $preset) {
            $existing = CourierStatusMap::query()->where('courier', CourierSetting::PATHAO)->where('status_key', CourierStatusMap::normalise($preset['status']))->orderBy('id')->get();
            $values = ['stage' => $preset['stage'], 'effect' => $preset['effect'], 'is_final' => $preset['final'], 'marks_cod_collected' => $preset['cod'], 'rank' => $preset['rank'], 'armed' => false];

            if ($existing->isEmpty()) {
                CourierStatusMap::query()->create(['courier' => CourierSetting::PATHAO, 'courier_status' => $preset['status']] + $values);
                $summary['added']++;

                continue;
            }

            foreach ($existing as $row) {
                if (self::untouched($row)) {
                    $row->forceFill($values)->save();
                    $summary['filled']++;
                } else {
                    $summary['kept']++;
                }
            }
        }

        return $summary;
    }

    /** Still exactly as it was made when the status was first seen: nobody has mapped it. */
    private static function untouched(CourierStatusMap $row): bool
    {
        return str_starts_with((string) $row->stage, 'Unknown status')
            && $row->effect === 'none'
            && ! $row->is_final
            && ! $row->marks_cod_collected
            && $row->rank === null;
    }
}
