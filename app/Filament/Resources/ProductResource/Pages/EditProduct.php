<?php

namespace App\Filament\Resources\ProductResource\Pages;

use App\Filament\Concerns\ReportsBrandVoice;
use App\Filament\Concerns\SavesExtraCategories;
use App\Filament\Resources\ProductResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditProduct extends EditRecord
{
    use ReportsBrandVoice;
    use SavesExtraCategories;

    protected static string $resource = ProductResource::class;

    protected function afterSave(): void
    {
        $this->saveExtraCategories();
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function brandVoiceAttributes(): array
    {
        return ['name', 'summary', 'description'];
    }
}
