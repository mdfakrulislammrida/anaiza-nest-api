<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AuthorizesResourceAccess;
use App\Filament\Resources\OrderResource\Pages;
use App\Filament\Resources\OrderResource\RelationManagers;
use App\Models\Order;
use App\Models\ShippingZone;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class OrderResource extends Resource
{
    use AuthorizesResourceAccess;

    protected static ?string $model = Order::class;

    protected static ?string $navigationIcon = 'heroicon-o-shopping-cart';

    protected static ?string $navigationGroup = 'Sales';

    protected static string $permissionKey = 'orders.manage';

    public const STATUSES = [
        'pending' => 'Pending',
        'processing' => 'Processing',
        'shipped' => 'Shipped',
        'delivered' => 'Delivered',
        'cancelled' => 'Cancelled',
    ];

    public const PAYMENT_METHODS = [
        'cod' => 'Cash on Delivery',
        'bkash' => 'bKash',
        'nagad' => 'Nagad',
        'card' => 'Card',
    ];

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('customer_id')
                    ->relationship('customer', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),
                Forms\Components\Select::make('status')
                    ->options(self::STATUSES)
                    ->default('pending')
                    ->required(),
                Forms\Components\Select::make('payment_method')
                    ->label('Payment Method')
                    ->options(self::PAYMENT_METHODS)
                    ->required(),
                Forms\Components\TextInput::make('subtotal')
                    ->label('Subtotal (BDT)')
                    ->required()
                    ->numeric()
                    ->minValue(0)
                    ->suffix('৳'),
                Forms\Components\Select::make('shipping_zone_id')
                    ->label('Shipping Zone')
                    ->relationship('shippingZone', 'name')
                    ->searchable()
                    ->preload()
                    ->live()
                    ->afterStateUpdated(function (?string $state, Forms\Set $set) {
                        if ($state && $zone = ShippingZone::find($state)) {
                            $set('delivery_fee', $zone->delivery_fee);
                        }
                    }),
                Forms\Components\TextInput::make('delivery_fee')
                    ->label('Delivery Fee (BDT)')
                    ->required()
                    ->numeric()
                    ->minValue(0)
                    ->suffix('৳'),
                Forms\Components\TextInput::make('total')
                    ->label('Total (BDT)')
                    ->required()
                    ->numeric()
                    ->minValue(0)
                    ->suffix('৳'),
                Forms\Components\Textarea::make('gift_note')
                    ->label('Order notes')
                    ->columnSpanFull(),
                Forms\Components\Toggle::make('is_gift')
                    ->label('This is a gift')
                    ->helperText('Ticked by the customer at checkout. Free, and it never changes the price.')
                    ->live(),
                Forms\Components\Textarea::make('gift_message')
                    ->label('Gift message')
                    ->rows(3)
                    ->maxLength(200)
                    ->live(debounce: 500)
                    ->helperText(fn (?string $state): string => mb_strlen($state ?? '').'/200 characters. This is what the "Print gift note" button puts on the card.')
                    ->visible(fn (Forms\Get $get): bool => (bool) $get('is_gift'))
                    ->columnSpanFull(),

                Forms\Components\Section::make('Marketing Attribution')
                    ->description('Captured from the checkout URL\'s UTM parameters at the time the order was placed -- read-only.')
                    ->schema([
                        Forms\Components\TextInput::make('utm_source')
                            ->label('Source')
                            ->disabled(),
                        Forms\Components\TextInput::make('utm_medium')
                            ->label('Medium')
                            ->disabled(),
                        Forms\Components\TextInput::make('utm_campaign')
                            ->label('Campaign')
                            ->disabled(),
                        Forms\Components\TextInput::make('utm_content')
                            ->label('Content')
                            ->disabled(),
                        Forms\Components\TextInput::make('utm_term')
                            ->label('Term')
                            ->disabled(),
                    ])
                    ->columns(2)
                    ->collapsed()
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('Order #')
                    ->sortable(),
                Tables\Columns\TextColumn::make('customer.name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => self::STATUSES[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        'pending' => 'gray',
                        'processing' => 'warning',
                        'shipped' => 'info',
                        'delivered' => 'success',
                        'cancelled' => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\IconColumn::make('is_gift')
                    ->label('Gift')
                    ->boolean()
                    ->trueIcon('heroicon-o-gift')
                    ->falseIcon('')
                    ->trueColor('warning'),
                Tables\Columns\TextColumn::make('payment_method')
                    ->formatStateUsing(fn (string $state): string => self::PAYMENT_METHODS[$state] ?? $state)
                    ->badge(),
                Tables\Columns\TextColumn::make('utm_source')
                    ->label('UTM Source')
                    ->placeholder('Direct/organic')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('utm_medium')
                    ->label('UTM Medium')
                    ->placeholder('—')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('utm_campaign')
                    ->label('UTM Campaign')
                    ->placeholder('—')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('shippingZone.name')
                    ->label('Shipping Zone')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('total')
                    ->formatStateUsing(fn (int $state): string => '৳'.number_format($state))
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(self::STATUSES),
                Tables\Filters\SelectFilter::make('payment_method')
                    ->label('Payment Method')
                    ->options(self::PAYMENT_METHODS),
                Tables\Filters\SelectFilter::make('utm_source')
                    ->label('UTM Source')
                    ->options(fn (): array => Order::query()
                        ->whereNotNull('utm_source')
                        ->distinct()
                        ->orderBy('utm_source')
                        ->pluck('utm_source', 'utm_source')
                        ->all()),
                Tables\Filters\SelectFilter::make('utm_campaign')
                    ->label('UTM Campaign')
                    ->options(fn (): array => Order::query()
                        ->whereNotNull('utm_campaign')
                        ->distinct()
                        ->orderBy('utm_campaign')
                        ->pluck('utm_campaign', 'utm_campaign')
                        ->all()),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('printGiftNote')
                    ->label('Print gift note')
                    ->icon('heroicon-o-printer')
                    ->url(fn (Order $record): string => route('orders.gift-note', $record))
                    ->openUrlInNewTab()
                    ->visible(fn (Order $record): bool => (bool) $record->is_gift),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\ItemsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOrders::route('/'),
            'create' => Pages\CreateOrder::route('/create'),
            'edit' => Pages\EditOrder::route('/{record}/edit'),
        ];
    }
}
