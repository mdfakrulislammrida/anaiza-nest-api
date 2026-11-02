<?php

namespace App\Filament\Resources\OrderResource\RelationManagers;

use App\Filament\Support\MobileCards;
use App\Models\CourierEvent;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * What happened to the parcel, newest first: who booked it and when, every status the courier reported (by status check or
 * webhook), what was ignored and why. Read-only: the history is append-only.
 */
class CourierEventsRelationManager extends RelationManager
{
    protected static string $relationship = 'courierEvents';

    protected static ?string $title = 'Courier history';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->paginated([10, 25])
            ->emptyStateHeading('Nothing yet')
            ->emptyStateDescription('A booking and the courier\'s reports will be listed here.')
            ->columns(MobileCards::columns([
                Tables\Columns\TextColumn::make('created_at')->label('When')->dateTime('j M Y, g:i A', 'Asia/Dhaka'),
                Tables\Columns\TextColumn::make('courier')->formatStateUsing(fn (string $state): string => ucfirst($state)),
                Tables\Columns\TextColumn::make('source')->badge(),
                Tables\Columns\TextColumn::make('event_type')->label('Event')->badge()->color('gray'),
                Tables\Columns\TextColumn::make('summary')->wrap(),
            ], fn (CourierEvent $event): array => [
                // When it happened and what it was, then the sentence in full (it wraps).
                ['left' => [MobileCards::text($event->created_at?->timezone('Asia/Dhaka')->format('j M, g:i A'), ['muted' => true])], 'right' => [MobileCards::badge($event->event_type, 'gray')]],
                ['left' => [MobileCards::text($event->summary, ['wrap' => true])]],
                ['left' => [MobileCards::text(trim(ucfirst((string) $event->courier).' · '.$event->source, ' ·'), ['muted' => true])]],
            ]));
    }
}
