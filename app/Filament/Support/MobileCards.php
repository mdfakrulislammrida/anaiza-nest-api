<?php

namespace App\Filament\Support;

use Closure;
use Filament\Tables\Actions\ActionGroup;
use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\ViewColumn;
use Illuminate\Database\Eloquent\Model;

/**
 * A list that reads as small cards on a phone and as the same table as ever from 768 px up.
 *
 * Filament's own Stack and Split layouts turn a whole table into a card list at EVERY width (the column headers, sorting and the table itself go),
 * so they cannot give "cards on a phone, the existing table on a desktop". This does it with Filament's own per-column breakpoint instead:
 *
 *   - every existing column is kept, exactly as defined, and only made visible from md up (`visibleFrom('md')`), so it is in the column toggle and in
 *     the table at 768 px and wider just as before;
 *   - one extra column, hidden from md up (`hiddenFrom('md')`), draws the whole row as a small card from the facts the owner needs at a glance.
 *
 * Below 768 px a little CSS (resources/views/filament/admin/mobile-styles.blade.php) hides the table header and stacks the row as a card.
 * Tapping the card opens the record, because the card is an ordinary cell and Filament links every cell of a clickable row.
 */
final class MobileCards
{
    /**
     * @param  array<int, Column>  $columns  the list's existing columns, unchanged
     * @param  Closure(Model): list<array{left?: list<array<string, mixed>>, right?: list<array<string, mixed>>}>  $rows  what the phone card shows, row by row
     * @return array<int, Column>
     */
    public static function columns(array $columns, Closure $rows): array
    {
        foreach ($columns as $column) {
            $column->visibleFrom('md');
        }

        return [...$columns, self::card($rows)];
    }

    /**
     * A row's actions: the buttons in a line as ever from 768 px up, and on a phone one "Actions" menu holding the same actions.
     * The factory is called twice, so each place has its own copies; they have the same names and do the same thing.
     *
     * @param  Closure(): array<int, mixed>  $make
     * @return array<int, mixed>
     */
    public static function actions(Closure $make): array
    {
        $inline = $make();

        foreach ($inline as $action) {
            $action->extraAttributes(['class' => 'am-desktop-only'], merge: true);
        }

        return [
            ...$inline,
            ActionGroup::make($make())
                ->label('Actions')
                ->icon('heroicon-m-ellipsis-vertical')
                ->button()
                ->color('gray')
                ->size('sm')
                ->extraAttributes(['class' => 'am-phone-only'], merge: true),
        ];
    }

    /** @param Closure(Model): list<array<string, mixed>> $rows */
    public static function card(Closure $rows): ViewColumn
    {
        return ViewColumn::make('phone_card')
            ->label('Summary')
            ->view('filament.admin.phone-card')
            ->viewData(['rows' => $rows])
            ->hiddenFrom('md');
    }

    /** Plain text. $options: strong (bold), muted (grey), wrap (may break anywhere, for long ids and addresses). */
    public static function text(?string $text, array $options = []): array
    {
        return ['text' => (string) $text] + $options;
    }

    /** A small coloured label (a status). Colours are Filament's: gray, info, success, warning, danger, primary. */
    public static function badge(?string $label, string $color = 'gray'): array
    {
        return ['badge' => (string) $label, 'color' => $color];
    }

    /** A picture (a thumbnail) at a fixed small size. */
    public static function picture(?string $url): array
    {
        return ['picture' => $url];
    }

    /** "৳1,450". */
    public static function taka(?int $amount): string
    {
        return '৳'.number_format((int) $amount);
    }
}
