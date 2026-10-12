<?php

namespace App\Filament\Resources\LandingPageResource\Pages;

use App\Filament\Resources\LandingPageResource;
use App\Models\LandingPage;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditLandingPage extends EditRecord
{
    protected static string $resource = LandingPageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // A link that shows the page whatever its state (draft, not open yet, ended), for two hours, never indexed.
            Actions\Action::make('preview')
                ->label('Preview')
                ->icon('heroicon-o-eye')
                ->color('gray')
                ->url(fn (): string => $this->getRecord()->previewUrl())
                ->openUrlInNewTab(),
            Actions\Action::make('duplicate')
                ->label('Duplicate')
                ->icon('heroicon-o-document-duplicate')
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription('Makes a draft copy you can change for a new campaign.')
                ->action(function (): void {
                    $copy = LandingPageResource::duplicate($this->getRecord());

                    $this->redirect(LandingPageResource::getUrl('edit', ['record' => $copy]));
                }),
            Actions\DeleteAction::make(),
        ];
    }

    protected function afterSave(): void
    {
        /** @var LandingPage $page */
        $page = $this->getRecord()->refresh();
        $notes = $page->notes ?? [];

        if ($notes === []) {
            return;
        }

        // The full list stays at the top of the page; the notification says how many things need a look.
        $this->fillForm();

        Notification::make()
            ->warning()
            ->title(count($notes).' '.(count($notes) === 1 ? 'thing was' : 'things were').' changed or need a look in your HTML')
            ->body('The list is at the top of this page.')
            ->persistent()
            ->send();
    }
}
