<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * What a status a courier reports means for us, in one table the owner can read and edit (Settings > Courier statuses).
 * A status we have never seen is added here as "unknown" the first time it arrives, so it shows up to be mapped.
 *
 * Two safety ideas live here:
 *  - status_key is the status with case, spaces, hyphens and underscores folded away ("On_the_way_to_Sorting HUB" and
 *    "on_the_way_to_sorting_hub" are one row), so a courier's changing spelling never makes a second row.
 *  - armed says whether the row acts on orders at all. A row that is not armed still shows its meaning ("In transit") on the order, but
 *    changes nothing: no order status, no cash, no end of checking. The ready-made Pathao rows start not armed, until the owner has
 *    looked at `php artisan couriers:preview-effects` and pressed "Arm Pathao statuses".
 */
class CourierStatusMap extends Model
{
    public const EFFECTS = [
        'none' => 'Nothing (just show it)',
        'shipped' => 'The order is shipped (moves it forward only)',
        'delivered' => 'The order is delivered',
        'partial' => 'Partly delivered (tell me, change nothing)',
        'returned' => 'Returned or cancelled by the courier (flag it, tell me)',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_final' => 'boolean',
            'marks_cod_collected' => 'boolean',
            'armed' => 'boolean',
            'rank' => 'integer',
            'seen_count' => 'integer',
            'last_seen_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (CourierStatusMap $row): void {
            $row->status_key = self::normalise((string) $row->courier_status);
        });
    }

    /** "On_the_way_to_Sorting HUB" -> "on_the_way_to_sorting_hub". */
    public static function normalise(?string $status): string
    {
        return strtolower(trim((string) preg_replace('/[\s_\-]+/', '_', trim((string) $status)), '_'));
    }

    /** The row for a status, whatever its spelling, without counting a sighting. */
    public static function lookup(string $courier, ?string $status): ?self
    {
        if ($status === null || trim($status) === '') {
            return null;
        }

        return static::query()->where('courier', $courier)->where('status_key', self::normalise($status))->orderBy('id')->first();
    }

    /**
     * The row for a status the courier just reported, counting the sighting. A status with no row yet gets one that does
     * nothing (stage "Unknown"), for the owner to map.
     */
    public static function noteSeen(string $courier, string $status, ?string $label = null): self
    {
        $row = self::lookup($courier, $status) ?? static::query()->create([
            'courier' => $courier, 'courier_status' => $status,
            'stage' => 'Unknown status'.(filled($label) && $label !== $status ? ' ('.mb_substr($label, 0, 50).')' : ''),
            'effect' => 'none', 'is_final' => false, 'marks_cod_collected' => false, 'rank' => null,
        ]);

        $row->forceFill(['seen_count' => $row->seen_count + 1, 'last_seen_at' => now()])->saveQuietly();

        return $row;
    }

    /** The same row as it acts while not armed: it still has its meaning and position, and does nothing to an order. Never saved. */
    public function inert(): self
    {
        $copy = $this->replicate();
        $copy->forceFill(['effect' => 'none', 'is_final' => false, 'marks_cod_collected' => false]);

        return $copy;
    }
}
