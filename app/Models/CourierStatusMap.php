<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * What a status a courier reports means for us, in one table the owner can read and edit (Settings > Courier statuses).
 * A status we have never seen is added here as "unknown" the first time it arrives, so it shows up to be mapped.
 */
class CourierStatusMap extends Model
{
    public const EFFECTS = [
        'none' => 'Nothing (just show it)',
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
            'rank' => 'integer',
            'seen_count' => 'integer',
            'last_seen_at' => 'datetime',
        ];
    }

    /**
     * The row for a status the courier just reported, counting the sighting. A status with no row yet gets one that does
     * nothing (stage "Unknown"), for the owner to map.
     */
    public static function noteSeen(string $courier, string $status, ?string $label = null): self
    {
        $row = static::query()->firstOrCreate(
            ['courier' => $courier, 'courier_status' => $status],
            ['stage' => 'Unknown status'.(filled($label) && $label !== $status ? ' ('.mb_substr($label, 0, 50).')' : ''), 'effect' => 'none', 'is_final' => false, 'marks_cod_collected' => false, 'rank' => null],
        );

        $row->forceFill(['seen_count' => $row->seen_count + 1, 'last_seen_at' => now()])->saveQuietly();

        return $row;
    }
}
