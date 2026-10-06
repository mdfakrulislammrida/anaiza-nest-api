<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CorporateEnquiry extends Model
{
    use HasFactory;

    public const STATUSES = [
        'new' => 'New',
        'quoted' => 'Quoted',
        'won' => 'Won',
        'lost' => 'Lost',
    ];

    protected $fillable = [
        'name',
        'company',
        'phone',
        'email',
        'quantity',
        'needed_by',
        'products_of_interest',
        'message',
        'status',
        'internal_notes',
    ];

    protected $attributes = [
        'status' => 'new',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'needed_by' => 'date',
        ];
    }
}
