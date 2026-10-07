<?php

namespace App\Filament\Resources\ProductReviewResource\Pages;

use App\Filament\Resources\ProductReviewResource;
use App\Models\ProductReview;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditProductReview extends EditRecord
{
    protected static string $resource = ProductReviewResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('approve')
                ->label('Approve')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn (): bool => $this->record->status !== ProductReview::APPROVED)
                ->action(fn () => ProductReviewResource::moderate($this->record->refresh(), ProductReview::APPROVED)),
            Actions\Action::make('reject')
                ->label('Reject')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn (): bool => $this->record->status !== ProductReview::REJECTED)
                ->action(fn () => ProductReviewResource::moderate($this->record->refresh(), ProductReview::REJECTED)),
            Actions\DeleteAction::make(),
        ];
    }

    /**
     * Only the reply is editable. Everything else about a review is what the customer wrote, and stays that way.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $reply = filled($data['admin_reply'] ?? null) ? trim((string) $data['admin_reply']) : null;

        return [
            'admin_reply' => $reply,
            'admin_replied_at' => $reply === null ? null : ($reply === $this->record->admin_reply ? $this->record->admin_replied_at : now()),
        ];
    }
}
