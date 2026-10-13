<?php

namespace App\Models;

use App\Models\Concerns\CleansContentHtml;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Page extends Model
{
    use CleansContentHtml;
    use HasFactory;

    protected $fillable = [
        'title',
        'slug',
        'content',
        'meta_title',
        'meta_description',
        'og_image',
    ];

    protected function contentHtmlAttributes(): array
    {
        return ['content'];
    }
}
