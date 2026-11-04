<?php

namespace App\Filament\Support;

use App\Support\Dhaka;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\Indicator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * One date filter for the admin lists: "From" and "Until" dates plus quick ranges (Today, Yesterday, Last 7 days, This month, Last month).
 * Every date here is a Dhaka calendar day: a day runs 00:00 to 23:59:59 in Dhaka time and is turned into the database's own time (UTC)
 * before it is asked, so an order placed at 23:30 UTC (05:30 the next morning in Dhaka) belongs to the Dhaka day it happened on.
 * A quick range wins over typed dates; choosing one clears the dates and typing a date clears the range, so the form never says two things.
 */
final class PlacedFilter
{
    public static function make(string $name = 'placed', string $column = 'created_at', string $label = 'Placed'): Filter
    {
        return Filter::make($name)
            ->label($label)
            ->form([
                Select::make('preset')
                    ->label($label.': quick range')
                    ->options(Dhaka::PRESETS)
                    ->placeholder('Any time')
                    ->live()
                    ->afterStateUpdated(function (Set $set, ?string $state): void {
                        if (filled($state)) {
                            $set('from', null);
                            $set('until', null);
                        }
                    }),
                DatePicker::make('from')
                    ->label($label.' from')
                    ->live()
                    ->afterStateUpdated(fn (Set $set, Get $get) => filled($get('from')) ? $set('preset', null) : null),
                DatePicker::make('until')
                    ->label($label.' until')
                    ->live()
                    ->afterStateUpdated(fn (Set $set, Get $get) => filled($get('until')) ? $set('preset', null) : null),
            ])
            ->query(fn (Builder $query, array $data): Builder => self::apply($query, $column, $data))
            ->indicateUsing(fn (array $data): array => self::indicators($label, $data));
    }

    /**
     * The Dhaka days the filter asks for, as "Y-m-d" (either may be null): the quick range if there is one, else the typed dates.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: ?string, 1: ?string}
     */
    public static function days(array $data): array
    {
        if (filled($data['preset'] ?? null) && ($range = Dhaka::preset((string) $data['preset'])) !== null) {
            return $range;
        }

        return [
            filled($data['from'] ?? null) ? substr((string) $data['from'], 0, 10) : null,
            filled($data['until'] ?? null) ? substr((string) $data['until'], 0, 10) : null,
        ];
    }

    /** @param  array<string, mixed>  $data */
    public static function apply(Builder $query, string $column, array $data): Builder
    {
        [$from, $until] = self::days($data);

        return $query
            ->when($from, fn (Builder $q, string $day) => $q->where($column, '>=', Dhaka::dayStart($day)))
            ->when($until, fn (Builder $q, string $day) => $q->where($column, '<=', Dhaka::dayEnd($day)));
    }

    /**
     * The chips shown above the list, each with its own way to remove it.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, Indicator>
     */
    public static function indicators(string $label, array $data): array
    {
        if (filled($data['preset'] ?? null) && isset(Dhaka::PRESETS[$data['preset']])) {
            return [Indicator::make($label.': '.Dhaka::PRESETS[$data['preset']])->removeField('preset')];
        }

        $indicators = [];

        if (filled($data['from'] ?? null)) {
            $indicators[] = Indicator::make($label.' from '.Carbon::parse($data['from'])->format('d M Y'))->removeField('from');
        }

        if (filled($data['until'] ?? null)) {
            $indicators[] = Indicator::make($label.' until '.Carbon::parse($data['until'])->format('d M Y'))->removeField('until');
        }

        return $indicators;
    }
}
