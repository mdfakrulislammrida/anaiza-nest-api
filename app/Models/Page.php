<?php

namespace App\Models;

use App\Models\Concerns\CleansContentHtml;
use App\Models\Concerns\RemembersSlugs;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Page extends Model
{
    use RemembersSlugs;

    public static function slugType(): string
    {
        return 'page';
    }

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
