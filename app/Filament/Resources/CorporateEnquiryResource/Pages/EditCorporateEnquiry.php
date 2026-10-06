<?php

namespace App\Filament\Resources\CorporateEnquiryResource\Pages;

use App\Filament\Resources\CorporateEnquiryResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditCorporateEnquiry extends EditRecord
{
    protected static string $resource = CorporateEnquiryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
