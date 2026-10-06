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
        'occasions' => 'Shop by occasion',
        'why_us' => 'Why Anaiza Nest',
        'custom_html' => 'Custom HTML',
    ];

    /**
     * The five built-in sections, in the order the homepage ships with. The storefront already falls
     * back to this order when the table is empty; restoreDefaults() puts the rows back so they can be
     * edited, reordered and retitled in the admin.
     */
    public const DEFAULT_ORDER = ['hero_banner', 'hot_deals', 'bestsellers', 'new_arrivals', 'newsletter'];

    /**
     * The example tiles "Add kit occasions" puts in a Shop by occasion section. They start disabled
     * and without a picture: they are prompts to finish and switch on, not live content.
     *
     * @var array<string, string> label => the path the tile points to until the admin changes it
     */
    public const KIT_OCCASIONS = [
        'Eid-ul-Fitr' => '/shop',
        'Eid-ul-Adha' => '/shop',
        'Pohela Boishakh' => '/shop',
        'Wedding season' => '/shop',
        'Housewarming' => '/shop',
        "Mother's Day" => '/shop',
        'Corporate gifting' => '/corporate-gifting',
    ];

    /**
     * The five lines "Insert kit lines" puts in a Why Anaiza Nest section, word for word.
     */
    public const KIT_REASONS = [
        'A small, edited range, so every piece has earned its place.',
        'Gift packaging is part of the product, not an extra.',
        'Packed and checked by hand before dispatch.',
        'Cash on delivery, and a real shop in Dhaka as well as the website.',
        'Part of Fast-Signs Group, in business in Bangladesh since 2003.',
    ];

    public const MAX_REASONS = 5;

    protected $fillable = [
        'type',
        'position',
        'is_enabled',
        'custom_title',
        'custom_subtitle',
        'custom_html',
        'deal_ends_at',
        'tiles',
        'reasons',
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

    /**
     * Adds the kit's seven occasions as disabled example tiles to the Shop by occasion section
     * (creating the section if there is none). A label that is already there is skipped, so running it
     * again changes nothing and never touches a tile the admin edited.
     *
     * @return list<string> the labels that were added
     */
    public static function addKitOccasions(): array
    {
        return DB::transaction(function (): array {
            $section = static::query()->where('type', 'occasions')->orderBy('id')->first()
                ?? static::create([
                    'type' => 'occasions',
                    'position' => static::query()->exists() ? ((int) static::query()->max('position')) + 1 : 0,
                    'is_enabled' => true,
                ]);

            $tiles = $section->tiles ?? [];
            $have = array_map(fn ($tile) => mb_strtolower((string) ($tile['label'] ?? '')), $tiles);
            $added = [];

            foreach (self::KIT_OCCASIONS as $label => $url) {
                if (in_array(mb_strtolower($label), $have, true)) {
                    continue;
                }

                $tiles[] = [
                    'label' => $label,
                    'image' => null,
                    'link_type' => 'custom',
                    'custom_url' => $url,
                    'is_enabled' => false,
                ];
                $added[] = $label;
            }

            if ($added !== []) {
                $section->update(['tiles' => $tiles]);
            }

            return $added;
        });
    }

    /**
     * Fills a Why Anaiza Nest section with the five kit lines and nothing else (creating the section
     * if there is none). A section that already has any lines is left exactly as it is.
     *
     * @return bool whether the lines were inserted
     */
    public static function insertKitLines(): bool
    {
        return DB::transaction(function (): bool {
            $section = static::query()->where('type', 'why_us')->orderBy('id')->first()
                ?? static::create([
                    'type' => 'why_us',
                    'position' => static::query()->exists() ? ((int) static::query()->max('position')) + 1 : 0,
                    'is_enabled' => true,
                ]);

            if (collect($section->reasons ?? [])->contains(fn ($row) => filled($row['line'] ?? null) || filled($row['title'] ?? null))) {
                return false;
            }

            $section->update(['reasons' => array_map(fn (string $line) => ['title' => null, 'line' => $line], self::KIT_REASONS)]);

            return true;
        });
    }

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'is_enabled' => 'boolean',
            'deal_ends_at' => 'datetime',
            'tiles' => 'array',
            'reasons' => 'array',
        ];
    }
}
