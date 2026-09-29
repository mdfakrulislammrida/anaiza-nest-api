<?php

namespace App\Filament\Resources\ProductResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

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
                            ->numeric()
                            ->minValue(0)
                            ->suffix('৳'),
                        Forms\Components\TextInput::make('stock_quantity')
                            ->label('Stock')
                            ->numeric()
                            ->minValue(0),
                        Forms\Components\TextInput::make('sku')
                            ->label('SKU')
                            ->maxLength(255),
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
