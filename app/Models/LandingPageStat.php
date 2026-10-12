<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LandingPageStat extends Model
{
    public $timestamps = false;

    protected $fillable = ['landing_page_id', 'day', 'views', 'add_to_carts'];
}
