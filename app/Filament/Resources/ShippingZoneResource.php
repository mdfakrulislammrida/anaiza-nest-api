<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AuthorizesResourceAccess;
use App\Filament\Resources\ShippingZoneResource\Pages;
use App\Filament\Support\DeleteGuard;
use App\Filament\Support\MobileCards;
use App\Models\ShippingZone;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ShippingZoneResource extends Resource
{
    use AuthorizesResourceAccess;

    protected static ?string $model = ShippingZone::class;

    protected static ?string $navigationIcon = 'heroicon-o-truck';

    protected static ?string $navigationGroup = 'Sales';

    protected static string $permissionKey = 'shipping_zones.manage';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->helperText("e.g. 'Inside Dhaka' or 'Outside Dhaka'")
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('delivery_fee')
                    ->label('Delivery Fee (BDT)')
                    ->required()
                    ->numeric()
                    ->minValue(0)
                    ->suffix('৳'),
                Forms\Components\TextInput::make('estimated_days')
                    ->label('Estimated Delivery')
                    ->helperText("e.g. '2-3 days'")
                    ->maxLength(50),
            ]);
    }

    public static function table(Table $table): Table
    {
        return MobileCards::phone($table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('delivery_fee')
                    ->formatStateUsing(fn (int $state): string => '৳'.number_format($state))
                    ->sortable(),
                Tables\Columns\TextColumn::make('estimated_days'),
            ])
            ->actions(MobileCards::actions(fn (): array => [
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()->using(DeleteGuard::single(['orders' => 'orders'], 'Orders keep their shipping zone, so change the zone on those orders first.')),
            ]))
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->using(DeleteGuard::bulk(['orders' => 'orders'], 'Orders keep their shipping zone, so change the zone on those orders first.'))
                        ->successNotificationTitle(null),
                ]),
            ]), fn ($record): array => [
                ['left' => [MobileCards::text($record->name, ['strong' => true, 'wrap' => true])]],
                ['left' => [MobileCards::text(MobileCards::taka($record->delivery_fee), ['strong' => true]), MobileCards::text($record->estimated_days, ['muted' => true])]],
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListShippingZones::route('/'),
            'create' => Pages\CreateShippingZone::route('/create'),
            'edit' => Pages\EditShippingZone::route('/{record}/edit'),
        ];
    }
}
