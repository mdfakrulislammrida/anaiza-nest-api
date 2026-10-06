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
            Actions\Action::make('addKitOccasions')
                ->label('Add kit occasions')
                ->icon('heroicon-o-calendar-days')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Add the kit occasions?')
                ->modalDescription('Adds Eid-ul-Fitr, Eid-ul-Adha, Pohela Boishakh, Wedding season, Housewarming, Mother\'s Day and Corporate gifting as example tiles in the Shop by occasion section (created if you have none). They are switched off and have no picture: open each one, choose where it links, add a picture if you want one, and switch it on. Tiles you already have are not touched.')
                ->modalSubmitActionLabel('Add')
                ->action(function (): void {
                    $added = HomepageSection::addKitOccasions();

                    if ($added === []) {
                        Notification::make()
                            ->title('All the kit occasions are already there')
                            ->body('Nothing was changed.')
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->success()
                        ->title(count($added) === 1 ? '1 tile added, switched off' : count($added).' tiles added, switched off')
                        ->body(implode(', ', $added))
                        ->send();
                }),
            Actions\Action::make('insertKitLines')
                ->label('Insert kit lines')
                ->icon('heroicon-o-sparkles')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Insert the kit lines?')
                ->modalDescription('Fills the Why Anaiza Nest section (created if you have none) with the five lines from the brand kit, word for word. A section that already has lines is left as it is.')
                ->modalSubmitActionLabel('Insert')
                ->action(function (): void {
                    if (! HomepageSection::insertKitLines()) {
                        Notification::make()
                            ->title('The Why Anaiza Nest section already has lines')
                            ->body('Nothing was changed.')
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->success()
                        ->title('Five kit lines inserted')
                        ->send();
                }),
            Actions\CreateAction::make(),
        ];
    }
}
