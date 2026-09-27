<?php

namespace App\Filament\Widgets;

use App\Models\Order;
use Filament\Tables;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Answers "which ad/placement is actually generating orders" by grouping
 * orders by UTM source + campaign. Rows are grouped by source (collapsible),
 * so expanding a source shows its campaign-level breakdown underneath.
 */
class UtmAttributionWidget extends BaseWidget
{
    protected static ?string $heading = 'Order Attribution by UTM Source';

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Order::query()
                    ->selectRaw('MIN(id) as id')
                    ->selectRaw("COALESCE(utm_source, 'Direct/organic') as utm_source")
                    ->selectRaw("COALESCE(utm_campaign, '—') as utm_campaign")
                    ->selectRaw('COUNT(*) as orders_count')
                    ->selectRaw('SUM(total) as revenue')
                    ->groupBy('utm_source', 'utm_campaign')
            )
            ->filters([
                Tables\Filters\SelectFilter::make('range')
                    ->label('Date range')
                    ->options([
                        '7' => 'Last 7 days',
                        '30' => 'Last 30 days',
                        '90' => 'Last 90 days',
                    ])
                    ->default('30')
                    ->query(function (Builder $query, array $data) {
                        if (! empty($data['value'])) {
                            $query->where('orders.created_at', '>=', now()->subDays((int) $data['value']));
                        }
                    }),
            ], layout: FiltersLayout::AboveContent)
            ->deferFilters(false)
            ->groups([
                Group::make('utm_source')->label('Source'),
            ])
            ->defaultGroup('utm_source')
            ->defaultSort('revenue', 'desc')
            ->paginated(false)
            ->columns([
                Tables\Columns\TextColumn::make('utm_source')
                    ->label('Source'),
                Tables\Columns\TextColumn::make('utm_campaign')
                    ->label('Campaign'),
                Tables\Columns\TextColumn::make('orders_count')
                    ->label('Orders')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('revenue')
                    ->label('Revenue')
                    ->formatStateUsing(fn ($state): string => '৳'.number_format((float) $state))
                    ->sortable(),
            ]);
    }
}
