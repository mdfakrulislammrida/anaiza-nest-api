<?php

namespace App\Filament\Resources\OrderResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('product_id')
                    ->relationship('product', 'name')
                    ->searchable()
                    ->preload(),
                Forms\Components\TextInput::make('product_name')
                    ->label('Product Name')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('quantity')
                    ->numeric()
                    ->minValue(1)
                    ->required(),
                Forms\Components\TextInput::make('price')
                    ->label('Price (BDT)')
                    ->numeric()
                    ->minValue(0)
                    ->suffix('৳')
                    ->required(),
                Forms\Components\TextInput::make('variant_name')
                    ->label('Variant Attribute'),
                Forms\Components\TextInput::make('variant_value')
                    ->label('Variant Value'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('product_name')
            ->columns([
                Tables\Columns\TextColumn::make('product_name')
                    ->label('Product'),
                Tables\Columns\TextColumn::make('variant_name')
                    ->formatStateUsing(fn ($record) => $record->variant_name
                        ? "{$record->variant_name}: {$record->variant_value}"
                        : '—')
                    ->label('Variant'),
                Tables\Columns\TextColumn::make('quantity'),
                Tables\Columns\TextColumn::make('price')
                    ->formatStateUsing(fn (int $state): string => '৳'.number_format($state)),
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
