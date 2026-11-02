<?php

namespace App\Filament\Resources\OrderResource\RelationManagers;

use App\Filament\Resources\ProductResource;
use App\Filament\Support\MobileCards;
use App\Models\OrderItem;
use App\Support\OrderItemPicture;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

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
            // The pictures come from the product and its variants: loaded together, so a long order costs no query per line.
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['product.images', 'product.variants.image']))
            ->columns(MobileCards::columns([
                Tables\Columns\ImageColumn::make('picture')
                    ->label('')
                    ->state(fn (OrderItem $record): ?string => OrderItemPicture::url($record))
                    ->defaultImageUrl(OrderItemPicture::placeholder())
                    ->size(48)
                    ->square()
                    ->extraImgAttributes(['loading' => 'lazy', 'alt' => '', 'style' => 'border-radius:2px;box-shadow:none;object-fit:cover;'])
                    ->url(fn (OrderItem $record): ?string => $record->product ? ProductResource::getUrl('edit', ['record' => $record->product]) : null),
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
            ], fn (OrderItem $item): array => [
                // A stacked row: the picture and the product, the variant, then quantity times price and the line total.
                ['left' => [MobileCards::picture(OrderItemPicture::url($item)), MobileCards::text($item->product_name, ['strong' => true, 'wrap' => true])]],
                ['left' => [MobileCards::text($item->variant_name ? "{$item->variant_name}: {$item->variant_value}" : null, ['muted' => true, 'wrap' => true])]],
                ['left' => [MobileCards::text($item->quantity.' × '.MobileCards::taka($item->price), ['muted' => true])], 'right' => [MobileCards::text(MobileCards::taka($item->quantity * $item->price), ['strong' => true])]],
            ]))
            ->filters([
                //
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make(),
            ])
            ->actions(MobileCards::actions(fn (): array => [
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]))
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }
}
