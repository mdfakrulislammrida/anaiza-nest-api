<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AuthorizesResourceAccess;
use App\Filament\Resources\CouponResource\Pages;
use App\Models\Coupon;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class CouponResource extends Resource
{
    use AuthorizesResourceAccess;

    protected static ?string $model = Coupon::class;

    protected static ?string $navigationIcon = 'heroicon-o-ticket';

    protected static ?string $navigationGroup = 'Sales';

    protected static string $permissionKey = 'coupons.manage';

    public const DISCOUNT_TYPES = [
        'percent' => 'Percent (%)',
        'fixed' => 'Fixed Amount (৳)',
    ];

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('code')
                    ->required()
                    ->maxLength(50)
                    ->unique(ignoreRecord: true)
                    ->formatStateUsing(fn (?string $state) => $state ? strtoupper($state) : $state)
                    ->dehydrateStateUsing(fn (?string $state) => $state ? strtoupper($state) : $state),
                Forms\Components\Select::make('discount_type')
                    ->options(self::DISCOUNT_TYPES)
                    ->required()
                    ->live(),
                Forms\Components\TextInput::make('amount')
                    ->label(fn (Forms\Get $get) => $get('discount_type') === 'percent' ? 'Discount (%)' : 'Discount Amount (৳)')
                    ->required()
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(fn (Forms\Get $get) => $get('discount_type') === 'percent' ? 100 : null),
                Forms\Components\DatePicker::make('expires_at')
                    ->label('Expiry Date'),
                Forms\Components\Toggle::make('is_active')
                    ->label('Active')
                    ->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->searchable()
                    ->badge(),
                Tables\Columns\TextColumn::make('discount_type')
                    ->formatStateUsing(fn (string $state): string => self::DISCOUNT_TYPES[$state] ?? $state),
                Tables\Columns\TextColumn::make('amount')
                    ->formatStateUsing(fn (Coupon $record): string => $record->discount_type === 'percent' ? "{$record->amount}%" : '৳'.number_format($record->amount)),
                Tables\Columns\TextColumn::make('expires_at')
                    ->date()
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_active')
                    ->boolean(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active'),
                Tables\Filters\SelectFilter::make('discount_type')
                    ->options(self::DISCOUNT_TYPES),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCoupons::route('/'),
            'create' => Pages\CreateCoupon::route('/create'),
            'edit' => Pages\EditCoupon::route('/{record}/edit'),
        ];
    }
}
