<?php

namespace App\Filament\Resources\CourierStatusMapResource\Pages;

use App\Filament\Resources\CourierStatusMapResource;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManageCourierStatusMaps extends ManageRecords
{
    protected static string $resource = CourierStatusMapResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()->label('Add a status')];
    }
}
