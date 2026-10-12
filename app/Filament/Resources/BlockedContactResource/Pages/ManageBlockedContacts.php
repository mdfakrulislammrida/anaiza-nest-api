<?php

namespace App\Filament\Resources\BlockedContactResource\Pages;

use App\Filament\Resources\BlockedContactResource;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManageBlockedContacts extends ManageRecords
{
    protected static string $resource = BlockedContactResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->mutateFormDataUsing(fn (array $data): array => BlockedContactResource::withCreator($data)),
        ];
    }
}
