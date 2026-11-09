<?php

namespace App\Filament\Resources\CategoryResource\Pages;

use App\Filament\Concerns\RefillsFormAfterSave;
use App\Filament\Concerns\ReportsBrandVoice;
use App\Filament\Resources\CategoryResource;
use App\Filament\Support\DeleteGuard;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditCategory extends EditRecord
{
    use RefillsFormAfterSave;
    use ReportsBrandVoice;

    protected static string $resource = CategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()->using(DeleteGuard::single(['products' => 'products'], CategoryResource::DELETE_HINT, ['products' => 'name'])),
        ];
    }

    protected function brandVoiceAttributes(): array
    {
        return ['name', 'intro_text', 'seo_description'];
    }
}
