<?php

namespace App\Filament\Widgets;

use App\Filament\Support\MobileCards;
use App\Models\Product;
use App\Support\ProductThumbnail;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class TopSellingProducts extends BaseWidget
{
    protected static ?string $heading = 'Top 5 Selling Products';

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return MobileCards::phone($table
            ->query(
                Product::query()
                    ->with(['images', 'variants.image'])
                    ->withSum('orderItems as total_sold', 'quantity')
                    ->orderByDesc('total_sold')
                    ->limit(5)
            )
            ->paginated(false)
            ->columns([
                Tables\Columns\ImageColumn::make('image')
                    ->label('')
                    ->getStateUsing(fn (Product $record): ?string => ProductThumbnail::url($record)),
                Tables\Columns\TextColumn::make('name'),
                Tables\Columns\TextColumn::make('sku')
                    ->label('SKU'),
                Tables\Columns\TextColumn::make('total_sold')
                    ->label('Units sold')
                    ->numeric()
                    ->default(0)
                    ->sortable(),
                Tables\Columns\TextColumn::make('price')
                    ->formatStateUsing(fn (int $state): string => '৳'.number_format($state)),
            ]), fn ($record): array => [
                ['left' => [MobileCards::picture(ProductThumbnail::url($record)), MobileCards::text($record->name, ['strong' => true, 'wrap' => true])]],
                ['left' => [MobileCards::text(((int) $record->total_sold).' sold', ['muted' => true]), MobileCards::text($record->sku, ['muted' => true])], 'right' => [MobileCards::text(MobileCards::taka($record->price), ['strong' => true])]],
            ]);
    }
}
