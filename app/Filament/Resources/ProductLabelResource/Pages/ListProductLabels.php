<?php

namespace App\Filament\Resources\ProductLabelResource\Pages;

use App\Filament\Resources\ProductLabelResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListProductLabels extends ListRecords
{
    protected static string $resource = ProductLabelResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
