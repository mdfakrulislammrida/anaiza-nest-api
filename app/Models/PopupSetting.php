<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PopupSetting extends Model
{
    /**
     * Every page group a popup's `pages` multi-select can target. 'all'
     * means show everywhere, regardless of what else is in the array.
     */
    public const PAGES = [
        'all' => 'All pages',
        'home' => 'Home page',
        'shop' => 'Shop page',
        'product' => 'Product pages',
        'category' => 'Category pages',
    ];

    public const TRIGGERS = [
        'delay' => 'After a delay',
        'scroll' => 'On scroll',
        'exit_intent' => 'On exit intent',
    ];

    protected $fillable = [
        'newsletter_enabled',
        'newsletter_trigger',
        'newsletter_delay_seconds',
        'newsletter_pages',
        'newsletter_image',
        'giftfinder_enabled',
        'giftfinder_trigger',
        'giftfinder_delay_seconds',
        'giftfinder_pages',
        'giftfinder_image',
    ];

    /**
     * Mirrors the migration's column defaults, but as real in-PHP defaults:
     * Eloquent doesn't re-fetch a model after an insert, so
     * firstOrCreate([]) alone would otherwise return an instance where
     * every one of these reads as null in the very same request, even
     * though the DB row itself has sensible values.
     */
    protected $attributes = [
        'newsletter_enabled' => false,
        'newsletter_trigger' => 'delay',
        'newsletter_delay_seconds' => 5,
        // Raw JSON, not a PHP array -- the 'array' cast json_decode()s
        // whatever is in $attributes on access, same as it would a value
        // read back from the database column.
        'newsletter_pages' => '["all"]',
        'giftfinder_enabled' => false,
        'giftfinder_trigger' => 'delay',
        'giftfinder_delay_seconds' => 8,
        'giftfinder_pages' => '["all"]',
    ];

    protected function casts(): array
    {
        return [
            'newsletter_enabled' => 'boolean',
            'newsletter_delay_seconds' => 'integer',
            'newsletter_pages' => 'array',
            'giftfinder_enabled' => 'boolean',
            'giftfinder_delay_seconds' => 'integer',
            'giftfinder_pages' => 'array',
        ];
    }
}
