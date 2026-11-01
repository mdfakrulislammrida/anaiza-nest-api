<?php

namespace App\Filament\Resources\ProductResource\RelationManagers;

use App\Support\Pricing;
use App\Support\Sku;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class VariantsRelationManager extends RelationManager
{
    protected static string $relationship = 'variants';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->label('Attribute (e.g. Colorway)')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('value')
                    ->label('Value (e.g. Burgundy)')
                    ->required()
                    ->maxLength(255),

                Forms\Components\Section::make('Overrides')
                    ->description('Leave any of these blank to inherit the product\'s own price, stock, or main image.')
                    ->collapsible()
                    ->schema([
                        Forms\Components\TextInput::make('price')
                            ->label('Price (BDT)')
                            ->numeric()
                            ->minValue(0)
                            ->suffix('৳'),
                        Forms\Components\TextInput::make('sale_price')
                            ->label('Sale price (BDT)')
                            ->helperText('Needs this variant\'s own price above, and must be lower than it. Leave blank for no sale (never 0).')
                            ->numeric()
                            ->rules(fn (Get $get): array => [Pricing::formRule(fn () => $get('price'), 'A variant sale price needs this variant\'s own price too. Fill in the price above, or leave the sale price blank.')])
                            ->suffix('৳'),
                        Forms\Components\TextInput::make('stock_quantity')
                            ->label('Stock')
                            ->numeric()
                            ->minValue(0),
                        Forms\Components\TextInput::make('sku')
                            ->label('SKU')
                            ->maxLength(255)
                            ->helperText('Leave empty to use the product\'s SKU. A variant with a SKU of its own is its own item in the product feeds, and that SKU is what the ad pixels send when it is chosen.')
                            ->rules([fn (?Model $record): \Closure => function (string $attribute, mixed $value, \Closure $fail) use ($record): void {
                                if (($problem = Sku::problem($value, true, $this->getOwnerRecord()->id, $record?->id)) !== null) {
                                    $fail($problem);
                                }
                            }])
                            ->dehydrateStateUsing(fn (?string $state): ?string => Sku::clean($state) ?: null),
                        Forms\Components\Select::make('image_id')
                            ->label('Image')
                            ->options(fn () => $this->getOwnerRecord()->images()
                                ->get()
                                ->mapWithKeys(fn ($image) => [
                                    $image->id => $image->alt_text ?: "Image #{$image->id} (sort {$image->sort_order})",
                                ]))
                            ->searchable(),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('value')
                    ->searchable(),
                Tables\Columns\TextColumn::make('price')
                    ->label('Price')
                    ->formatStateUsing(fn (?int $state): string => $state === null ? 'Inherits' : '৳'.number_format($state)),
                Tables\Columns\TextColumn::make('stock_quantity')
                    ->label('Stock')
                    ->formatStateUsing(fn (?int $state): string => $state === null ? 'Inherits' : (string) $state),
                Tables\Columns\ImageColumn::make('image.url')
                    ->label('Image')
                    ->disk('public'),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make(),
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
}
