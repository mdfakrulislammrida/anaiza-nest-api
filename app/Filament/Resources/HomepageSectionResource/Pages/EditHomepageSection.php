<?php

namespace App\Filament\Resources\HomepageSectionResource\Pages;

use App\Filament\Resources\HomepageSectionResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditHomepageSection extends EditRecord
{
    protected static string $resource = HomepageSectionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
