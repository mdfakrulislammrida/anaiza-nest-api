<?php

namespace App\Filament\Resources\EmailTemplateResource\Pages;

use App\Filament\Resources\EmailTemplateResource;
use App\Models\EmailTemplate;
use Filament\Resources\Pages\ListRecords;

class ListEmailTemplates extends ListRecords
{
    protected static string $resource = EmailTemplateResource::class;

    public function mount(): void
    {
        // The five emails always have a row to open, even on a fresh install.
        EmailTemplate::ensureAll();

        parent::mount();
    }
}
