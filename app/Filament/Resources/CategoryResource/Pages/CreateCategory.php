<?php

namespace App\Filament\Resources\CategoryResource\Pages;

use App\Filament\Concerns\ReportsBrandVoice;
use App\Filament\Resources\CategoryResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCategory extends CreateRecord
{
    use ReportsBrandVoice;

    protected static string $resource = CategoryResource::class;

    protected function brandVoiceAttributes(): array
    {
        return ['name', 'intro_text', 'seo_description'];
    }
}
