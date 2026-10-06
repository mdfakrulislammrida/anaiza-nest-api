<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentSetting extends Model
{
    protected $fillable = [
        'bkash_number',
        'nagad_number',
        'rocket_number',
        'cod_enabled',
    ];

    /**
     * Eloquent does not re-read a row after inserting it, so a settings row created on first read
     * (the API does that) would otherwise report cash on delivery as null instead of on.
     */
    protected $attributes = [
        'cod_enabled' => true,
    ];

    protected function casts(): array
    {
        return [
            'cod_enabled' => 'boolean',
        ];
    }
}
