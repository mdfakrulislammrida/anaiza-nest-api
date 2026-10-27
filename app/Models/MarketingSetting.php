<?php

namespace App\Models;

use App\Models\Concerns\HasSecretHints;
use Illuminate\Database\Eloquent\Model;

class MarketingSetting extends Model
{
    use HasSecretHints;

    /** The fields that hold a secret: encrypted at rest, blank in the admin form, kept when left blank on save. */
    public const SECRET_FIELDS = ['meta_capi_access_token', 'tiktok_events_api_access_token'];

    protected $fillable = [
        'gtm_container_id',
        'meta_pixel_id',
        'meta_capi_access_token',
        'ga4_id',
        'tiktok_pixel_id',
        'server_container_url',
        'meta_capi_enabled',
        'tiktok_events_enabled',
        'tiktok_events_api_access_token',
        'meta_test_event_code',
        'tiktok_test_event_code',
    ];

    /** Never serialised with its secrets, whatever calls toArray() or toJson() on it (the admin form is filled from toArray()). */
    protected $hidden = self::SECRET_FIELDS;

    /** A new row sends to both platforms, as the shop always did. (A row made by firstOrCreate() is not re-read, so the default is here too.) */
    protected $attributes = [
        'meta_capi_enabled' => true,
        'tiktok_events_enabled' => true,
    ];

    /** What is wrong with a server container address, in plain words, or null when it is fine (or empty). */
    public static function serverUrlProblem(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        if (! str_starts_with($value, 'https://')) {
            return 'The address must start with https://';
        }

        if (str_ends_with($value, '/')) {
            return 'Leave out the slash at the end.';
        }

        if (preg_match('#^https://[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+(:[0-9]{2,5})?$#i', $value) !== 1) {
            return 'That does not look like an address: use just the name, like https://metrics.example.com (no path, no spaces).';
        }

        return null;
    }

    protected function casts(): array
    {
        return [
            'meta_capi_access_token' => 'encrypted',
            'tiktok_events_api_access_token' => 'encrypted',
            'meta_capi_enabled' => 'boolean',
            'tiktok_events_enabled' => 'boolean',
        ];
    }
}
