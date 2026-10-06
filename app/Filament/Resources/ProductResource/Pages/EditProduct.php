<?php

namespace App\Filament\Resources\ProductResource\Pages;

use App\Filament\Concerns\ReportsBrandVoice;
use App\Filament\Resources\ProductResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditProduct extends EditRecord
{
    use ReportsBrandVoice;

    protected static string $resource = ProductResource::class;

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
