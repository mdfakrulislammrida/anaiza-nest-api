<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An address a category, page or landing page used to have (see App\Models\Concerns\RemembersSlugs).
 */
class SlugRedirect extends Model
{
    protected $fillable = ['type', 'model_id', 'old_slug'];
}
