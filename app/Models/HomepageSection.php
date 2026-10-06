<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class HomepageSection extends Model
{
    use HasFactory;

    /**
     * Every section type the homepage builder can place, keyed by the
     * value stored in `type`. 'custom_html' is the escape hatch for
     * arbitrary admin-authored content.
     */
    public const TYPES = [
        'hero_banner' => 'Hero Banner',
        'hot_deals' => 'Special prices',
        'bestsellers' => 'Featured gifts',
        'new_arrivals' => 'New arrivals',
        'newsletter' => 'Newsletter',
        'custom_html' => 'Custom HTML',
    ];

    /**
     * The five built-in sections, in the order the homepage ships with. The storefront already falls
     * back to this order when the table is empty; restoreDefaults() puts the rows back so they can be
     * edited, reordered and retitled in the admin.
     */
    public const DEFAULT_ORDER = ['hero_banner', 'hot_deals', 'bestsellers', 'new_arrivals', 'newsletter'];

    protected $fillable = [
        'type',
        'position',
        'is_enabled',
        'custom_title',
        'custom_subtitle',
        'custom_html',
        'deal_ends_at',
    ];

    /**
     * Creates whichever default sections are missing, and nothing else. Idempotent: a section that
     * exists (disabled, retitled, moved, anything) is left exactly as it is, and a second run does
     * nothing. Missing ones go after the last existing position, in the default order.
     *
     * @return list<string> the types that were created
     */
    public static function restoreDefaults(): array
    {
        return DB::transaction(function (): array {
            $present = static::query()->pluck('type')->all();
            $missing = array_values(array_diff(self::DEFAULT_ORDER, $present));
            $position = static::query()->exists() ? ((int) static::query()->max('position')) + 1 : 0;

            foreach ($missing as $type) {
                static::create(['type' => $type, 'position' => $position++, 'is_enabled' => true]);
            }

            return $missing;
        });
    }

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'is_enabled' => 'boolean',
            'deal_ends_at' => 'datetime',
        ];
    }
}
