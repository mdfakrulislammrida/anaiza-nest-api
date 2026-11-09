<?php

namespace App\Filament\Resources\ProductResource\Pages;

use App\Filament\Concerns\ReportsBrandVoice;
use App\Filament\Concerns\SavesExtraCategories;
use App\Filament\Resources\ProductResource;
use Filament\Resources\Pages\CreateRecord;

class CreateProduct extends CreateRecord
{
    use ReportsBrandVoice;
    use SavesExtraCategories;

    protected static string $resource = ProductResource::class;

    protected function afterCreate(): void
    {
        $this->saveExtraCategories();
    }

    protected function brandVoiceAttributes(): array
    {
        return ['name', 'summary', 'description'];
    }
}
