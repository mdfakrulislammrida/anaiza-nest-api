<?php

namespace App\Filament\Resources\ArticleResource\Pages;

use App\Filament\Concerns\ReportsBrandVoice;
use App\Filament\Resources\ArticleResource;
use Filament\Resources\Pages\CreateRecord;

class CreateArticle extends CreateRecord
{
    use ReportsBrandVoice;

    protected static string $resource = ArticleResource::class;

    protected function brandVoiceAttributes(): array
    {
        return ['title', 'content'];
    }
}
