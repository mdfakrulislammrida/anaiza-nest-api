<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentSetting extends Model
{
    protected $fillable = [
        'bkash_number',
        'nagad_number',
        'cod_enabled',
    ];

    protected function casts(): array
    {
        return [
            'cod_enabled' => 'boolean',
        ];
    }
}
