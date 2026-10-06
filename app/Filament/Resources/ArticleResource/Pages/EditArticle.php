<?php

namespace App\Filament\Resources\ArticleResource\Pages;

use App\Filament\Concerns\ReportsBrandVoice;
use App\Filament\Resources\ArticleResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditArticle extends EditRecord
{
    use ReportsBrandVoice;

    protected static string $resource = ArticleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function brandVoiceAttributes(): array
    {
        return ['title', 'content'];
    }
}
