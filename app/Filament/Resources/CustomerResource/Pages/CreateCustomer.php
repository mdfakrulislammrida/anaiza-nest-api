<?php

namespace App\Filament\Resources\CustomerResource\Pages;

use App\Filament\Resources\CustomerResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCustomer extends CreateRecord
{
    protected static string $resource = CustomerResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Filament-created staff accounts don't need storefront login access;
        // give them an unusable random password to satisfy the not-null column.
        $data['password'] = bcrypt(\Illuminate\Support\Str::random(32));

        return $data;
    }
}
