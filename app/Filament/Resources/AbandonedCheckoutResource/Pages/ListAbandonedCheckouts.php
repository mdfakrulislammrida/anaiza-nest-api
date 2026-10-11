<?php

namespace App\Filament\Resources\AbandonedCheckoutResource\Pages;

use App\Filament\Resources\AbandonedCheckoutResource;
use App\Filament\Widgets\AbandonedCheckoutStats;
use Filament\Resources\Pages\ListRecords;

class ListAbandonedCheckouts extends ListRecords
{
    protected static string $resource = AbandonedCheckoutResource::class;

    protected function getHeaderWidgets(): array
    {
        return [AbandonedCheckoutStats::class];
    }
}
