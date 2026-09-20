<?php

namespace App\Filament\Resources\ProductLabelResource\Pages;

use App\Filament\Resources\ProductLabelResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditProductLabel extends EditRecord
{
    protected static string $resource = ProductLabelResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
