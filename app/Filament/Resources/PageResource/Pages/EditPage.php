<?php

namespace App\Filament\Resources\PageResource\Pages;

use App\Filament\Concerns\ReportsBrandVoice;
use App\Filament\Resources\PageResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditPage extends EditRecord
{
    use ReportsBrandVoice;

    protected static string $resource = PageResource::class;

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
