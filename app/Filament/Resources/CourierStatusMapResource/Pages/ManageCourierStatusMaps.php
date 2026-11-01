<?php

namespace App\Filament\Resources\CourierStatusMapResource\Pages;

use App\Filament\Resources\CourierStatusMapResource;
use App\Models\CourierSetting;
use App\Models\CourierStatusMap;
use App\Support\Courier\CourierEffectPreview;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;

class ManageCourierStatusMaps extends ManageRecords
{
    protected static string $resource = CourierStatusMapResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('armPathao')
                ->label('Arm Pathao statuses')
                ->icon('heroicon-o-bolt')
                ->color('warning')
                ->visible(fn (): bool => CourierStatusMap::query()->where('courier', CourierSetting::PATHAO)->where('armed', false)->exists())
                ->requiresConfirmation()
                ->modalHeading('Let the Pathao statuses change orders?')
                ->modalDescription(function (): string {
                    $count = count(CourierEffectPreview::rows());

                    return 'Until now the Pathao statuses below only show what a parcel is doing. Once armed, the next status check moves orders forward (to Shipped, to Delivered with the cash recorded) as the table says. '
                        .($count > 0
                            ? "{$count} order(s) would change right away. See exactly which with: php artisan couriers:preview-effects. Orders whose status the shop has known for more than 24 hours are changed without emailing the customer."
                            : 'No order would change right away.');
                })
                ->modalSubmitActionLabel('Arm them')
                ->action(function (): void {
                    $armed = CourierStatusMap::query()->where('courier', CourierSetting::PATHAO)->where('armed', false)->update(['armed' => true]);

                    Notification::make()->success()->title('Pathao statuses armed')->body("{$armed} status(es) now act on orders at the next status check.")->send();
                }),
            Actions\CreateAction::make()->label('Add a status'),
        ];
    }
}
