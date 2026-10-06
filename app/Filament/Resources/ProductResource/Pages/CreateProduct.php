<?php

namespace App\Filament\Resources\ProductResource\Pages;

use App\Filament\Concerns\ReportsBrandVoice;
use App\Filament\Resources\ProductResource;
use Filament\Resources\Pages\CreateRecord;

class CreateProduct extends CreateRecord
{
    use ReportsBrandVoice;

    protected static string $resource = ProductResource::class;

    protected function brandVoiceAttributes(): array
    {
        return ['name', 'summary', 'description'];
    }
}
