<?php

namespace App\Filament\Resources\LandingPageResource\Pages;

use App\Filament\Resources\LandingPageResource;
use Filament\Resources\Pages\CreateRecord;

class CreateLandingPage extends CreateRecord
{
    protected static string $resource = LandingPageResource::class;

    protected function getRedirectUrl(): string
    {
        // Straight to the edit page, where the list of what was cleaned from the HTML is.
        return static::getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
