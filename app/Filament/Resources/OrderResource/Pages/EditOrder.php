<?php

namespace App\Filament\Resources\OrderResource\Pages;

use App\Filament\Resources\OrderResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditOrder extends EditRecord
{
    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('printGiftNote')
                ->label('Print gift note')
                ->icon('heroicon-o-printer')
                ->url(fn (): string => route('orders.gift-note', $this->record))
                ->openUrlInNewTab()
                ->visible(fn (): bool => (bool) $this->record->is_gift),
            Actions\DeleteAction::make(),
        ];
    }
}
