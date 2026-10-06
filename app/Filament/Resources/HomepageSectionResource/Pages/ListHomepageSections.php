<?php

namespace App\Filament\Resources\HomepageSectionResource\Pages;

use App\Filament\Resources\HomepageSectionResource;
use App\Models\HomepageSection;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListHomepageSections extends ListRecords
{
    protected static string $resource = HomepageSectionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('restoreDefaultSections')
                ->label('Restore default sections')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Restore the default sections?')
                ->modalDescription('Adds any of the five built-in sections that are missing: hero, special prices, featured gifts, new arrivals and newsletter. Sections you already have, and every change you made to them, stay exactly as they are.')
                ->modalSubmitActionLabel('Restore')
                ->action(function (): void {
                    $created = HomepageSection::restoreDefaults();

                    if ($created === []) {
                        Notification::make()
                            ->title('All five default sections already exist')
                            ->body('Nothing was changed.')
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->success()
                        ->title(count($created) === 1 ? '1 section restored' : count($created).' sections restored')
                        ->body(collect($created)->map(fn (string $type): string => HomepageSection::TYPES[$type] ?? $type)->implode(', '))
                        ->send();
                }),
            Actions\CreateAction::make(),
        ];
    }
}
