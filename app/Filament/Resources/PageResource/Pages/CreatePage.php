<?php

namespace App\Filament\Resources\PageResource\Pages;

use App\Filament\Concerns\ReportsBrandVoice;
use App\Filament\Resources\PageResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePage extends CreateRecord
{
    use ReportsBrandVoice;

    protected static string $resource = PageResource::class;

    protected function brandVoiceAttributes(): array
    {
        return ['title', 'content'];
    }
}
