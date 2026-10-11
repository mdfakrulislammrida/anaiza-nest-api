<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AuthorizesResourceAccess;
use App\Filament\Resources\CouponResource\Pages;
use App\Models\Coupon;
use App\Support\Contact;
use App\Support\Coupons\CouponService;
use App\Support\Dhaka;
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

    /** What a coupon is right now, for the list. */
    public static function status(Coupon $coupon): string
    {
        return match (true) {
            ! $coupon->is_active => 'Off',
            $coupon->single_use && $coupon->used_count > 0 => 'Used',
            $coupon->valid_from !== null && $coupon->valid_from->isFuture() => 'Not yet',
            $coupon->valid_until !== null && $coupon->valid_until->isPast() => 'Ended',
            default => 'Live',
        };
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('The code')
                    ->schema([
                        Forms\Components\TextInput::make('code')
                            ->required()
                            ->maxLength(40)
                            ->unique(ignoreRecord: true)
                            ->helperText('Letters and digits. Customers can type it in any case.')
                            ->formatStateUsing(fn (?string $state) => $state ? strtoupper($state) : $state)
                            ->dehydrateStateUsing(fn (?string $state) => $state ? CouponService::normalize($state) : $state),
                        Forms\Components\Toggle::make('is_active')
                            ->label('Active')
                            ->default(true),
                        Forms\Components\Select::make('discount_type')
                            ->options(self::DISCOUNT_TYPES)
                            ->required()
                            ->live(),
                        Forms\Components\TextInput::make('amount')
                            ->label(fn (Forms\Get $get) => $get('discount_type') === 'percent' ? 'Discount (%)' : 'Discount Amount (৳)')
                            ->required()
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(fn (Forms\Get $get) => $get('discount_type') === 'percent' ? 100 : null),
                        Forms\Components\TextInput::make('min_order')
                            ->label('Minimum order (৳, optional)')
                            ->helperText('Measured on the cart before delivery. Blank: no minimum.')
                            ->numeric()->integer()->minValue(1),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('When it works')
                    ->description('Exact times, entered and shown in Dhaka time. Leave a time blank for no start or no end.')
                    ->schema([
                        Forms\Components\DateTimePicker::make('valid_from')
                            ->label('Starts')
                            ->seconds(false)
                            ->timezone(Dhaka::ZONE),
                        Forms\Components\DateTimePicker::make('valid_until')
                            ->label('Ends')
                            ->seconds(false)
                            ->timezone(Dhaka::ZONE)
                            ->after('valid_from'),
                        Forms\Components\Toggle::make('single_use')
                            ->label('Works once')
                            ->helperText('After one order uses it, it stops working.'),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('Only for one person (optional)')
                    ->description('A code bound to an email or a phone number works only for an order from that email or that number. Codes made by cart recovery are bound this way.')
                    ->schema([
                        Forms\Components\TextInput::make('bound_email')
                            ->label('Email')
                            ->email()
                            ->maxLength(255)
                            ->dehydrateStateUsing(fn (?string $state) => Contact::email($state)),
                        Forms\Components\TextInput::make('bound_phone')
                            ->label('Phone')
                            ->tel()
                            ->maxLength(20)
                            ->dehydrateStateUsing(fn (?string $state) => Contact::phone($state)),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('Use')
                    ->description('Filled in by the shop when an order uses the code.')
                    ->visibleOn('edit')
                    ->schema([
                        Forms\Components\Placeholder::make('usage')
                            ->hiddenLabel()
                            ->content(fn (?Coupon $record): string => $record === null ? '' : ($record->used_count > 0
                                ? "Used {$record->used_count} ".($record->used_count === 1 ? 'time' : 'times').', last on '.Dhaka::readable($record->used_at).($record->used_order_id ? " (order #{$record->used_order_id})" : '').'.'
                                : 'Not used yet.')),
                    ]),
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
                Tables\Columns\TextColumn::make('amount')
                    ->label('Discount')
                    ->formatStateUsing(fn (Coupon $record): string => $record->valueLabel()
                        .($record->min_order ? ', over ৳'.number_format($record->min_order) : '')),
                Tables\Columns\TextColumn::make('status')
                    ->label('Now')
                    ->state(fn (Coupon $record): string => self::status($record))
                    ->badge()
                    ->color(fn (string $state): string => $state === 'Live' ? 'success' : 'gray'),
                Tables\Columns\TextColumn::make('valid_until')
                    ->label('Ends (Dhaka)')
                    ->formatStateUsing(fn ($state) => $state ? Dhaka::readable($state) : 'No end')
                    ->sortable(),
                Tables\Columns\TextColumn::make('used_count')
                    ->label('Used')
                    ->sortable(),
                Tables\Columns\TextColumn::make('bound')
                    ->label('For')
                    ->state(fn (Coupon $record): string => $record->isBound() ? implode(' / ', array_filter([$record->bound_email, $record->bound_phone])) : 'Anyone')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('source')
                    ->formatStateUsing(fn (string $state): string => $state === 'recovery' ? 'Cart recovery' : 'Made by hand')
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active'),
                Tables\Filters\SelectFilter::make('discount_type')
                    ->options(self::DISCOUNT_TYPES),
                Tables\Filters\SelectFilter::make('source')
                    ->options(['manual' => 'Made by hand', 'recovery' => 'Cart recovery']),
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
