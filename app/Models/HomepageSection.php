<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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
        'hot_deals' => 'Hot Deals',
        'bestsellers' => 'Bestsellers',
        'new_arrivals' => 'New Arrivals',
        'newsletter' => 'Newsletter',
        'custom_html' => 'Custom HTML',
    ];

    protected $fillable = [
        'type',
        'position',
        'is_enabled',
        'custom_title',
        'custom_html',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'is_enabled' => 'boolean',
        ];
    }
}
