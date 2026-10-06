<?php

namespace App\Filament\Resources\OrderResource\Pages;

use App\Filament\Resources\OrderResource;
use App\Filament\Support\PaymentActions;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditOrder extends EditRecord
{
    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            PaymentActions::verify(Actions\Action::class)->record($this->record)->after(fn () => $this->refreshPaymentFields()),
            PaymentActions::fail(Actions\Action::class)->record($this->record)->after(fn () => $this->refreshPaymentFields()),
            Actions\Action::make('printGiftNote')
                ->label('Print gift note')
                ->icon('heroicon-o-printer')
                ->url(fn (): string => route('orders.gift-note', $this->record))
                ->openUrlInNewTab()
                ->visible(fn (): bool => (bool) $this->record->is_gift),
            Actions\DeleteAction::make(),
        ];
    }

    /**
     * The read-only payment fields are form state, so they are re-read after a decision to show the new status.
     */
    private function refreshPaymentFields(): void
    {
        $this->refreshFormData(['payment_status', 'payment_note']);
    }
}
