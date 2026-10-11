<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A slide of the homepage banner slider. At most five are shown, in order, and only while they are switched on and inside
 * their start and end dates.
 */
class Banner extends Model
{
    use HasFactory;

    /** The most slides the slider will ever show. */
    public const MAX_SLIDES = 5;

    protected $fillable = [
        'image_url',
        'headline',
        'subtext',
        'link',
        'button_text',
        'starts_at',
        'ends_at',
        'sort_order',
        'is_active',
        'image_width',
        'image_height',
        'image_1280',
        'image_640',
        'mobile_image',
        'mobile_width',
        'mobile_height',
        'mobile_auto_generated',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'image_width' => 'integer',
            'image_height' => 'integer',
            'mobile_width' => 'integer',
            'mobile_height' => 'integer',
            'mobile_auto_generated' => 'boolean',
        ];
    }

    /**
     * Slides that are showing right now: on, started and not yet ended.
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query
            ->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now()));
    }
}
