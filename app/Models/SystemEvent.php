<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One thing the server noticed about itself. Use App\Support\System\SystemEvents rather than this directly.
 */
class SystemEvent extends Model
{
    protected $fillable = ['type', 'level', 'message'];
}
