<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AuthorizesResourceAccess;
use App\Filament\Resources\ProductLabelResource\Pages;
use App\Models\ProductLabel;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Support\Colors\Color;
use Filament\Tables;
use Filament\Tables\Table;

class ProductLabelResource extends Resource
{
    use AuthorizesResourceAccess;

    protected static ?string $model = ProductLabel::class;

    protected static ?string $navigationIcon = 'heroicon-o-bookmark';

    protected static ?string $navigationGroup = 'Catalog';

    protected static ?string $navigationLabel = 'Labels';

    protected static ?string $modelLabel = 'Product Label';

    protected static string $permissionKey = 'product_labels.manage';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->label('Badge text (e.g. New, Gift-ready, Special price)')
                    ->required()
                    ->maxLength(255),
                Forms\Components\ColorPicker::make('badge_color')
                    ->required()
                    ->default('#AD8A50'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->badge()
                    ->color(fn (ProductLabel $record) => Color::hex($record->badge_color))
                    ->searchable(),
                Tables\Columns\ColorColumn::make('badge_color'),
                Tables\Columns\TextColumn::make('products_count')
                    ->counts('products')
                    ->label('Products'),
            ])
            ->filters([
                //
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
            'index' => Pages\ListProductLabels::route('/'),
            'create' => Pages\CreateProductLabel::route('/create'),
            'edit' => Pages\EditProductLabel::route('/{record}/edit'),
        ];
    }
}
