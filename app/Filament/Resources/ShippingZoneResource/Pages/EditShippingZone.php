<?php

namespace App\Filament\Resources\ShippingZoneResource\Pages;

use App\Filament\Resources\ShippingZoneResource;
use App\Filament\Support\DeleteGuard;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditShippingZone extends EditRecord
{
    protected static string $resource = ShippingZoneResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()->using(DeleteGuard::single(['orders' => 'orders'], 'Orders keep their shipping zone, so change the zone on those orders first.')),
        ];
    }
}
