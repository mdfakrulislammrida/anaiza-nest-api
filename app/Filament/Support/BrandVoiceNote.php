<?php

namespace App\Filament\Support;

use App\Support\BrandVoice;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Get;
use Illuminate\Support\HtmlString;

/**
 * The brand-voice check as a form component. It sits under a field, appears only when there is
 * something to say, and never blocks saving: it is a Placeholder, which is not part of the saved data.
 * The field it watches needs to be `->live()` (or `->live(onBlur: true)`) so the note follows what is typed.
 */
final class BrandVoiceNote
{
    /**
     * A note about one field of the same form level.
     */
    public static function under(string $statePath): Placeholder
    {
        return Placeholder::make('brand_voice_'.str_replace('.', '_', $statePath))
            ->hiddenLabel()
            ->content(fn (Get $get): HtmlString => new HtmlString(self::render(BrandVoice::notes($get($statePath)))))
            ->visible(fn (Get $get): bool => BrandVoice::notes($get($statePath)) !== [])
            ->columnSpanFull();
    }

    /**
     * One note for several fields, each note prefixed with the field's label.
     *
     * @param  array<string, string>  $fields  state path => label
     */
    public static function forFields(array $fields): Placeholder
    {
        $collect = function (Get $get) use ($fields): array {
            $notes = [];

            foreach ($fields as $path => $label) {
                foreach (BrandVoice::notes($get($path)) as $note) {
                    $notes[] = "{$label}: {$note}";
                }
            }

            return $notes;
        };

        return Placeholder::make('brand_voice_group')
            ->hiddenLabel()
            ->content(fn (Get $get): HtmlString => new HtmlString(self::render($collect($get))))
            ->visible(fn (Get $get): bool => $collect($get) !== [])
            ->columnSpanFull();
    }

    /**
     * @param  list<string>  $notes
     */
    public static function render(array $notes): string
    {
        if ($notes === []) {
            return '';
        }

        $items = implode('', array_map(fn (string $note): string => '<li>'.e($note).'</li>', $notes));

        return '<div class="text-sm" style="border-left:3px solid #d4b786;padding:.25rem .75rem;color:#6f675c">'
            .'<strong style="color:#2a2622">Brand check</strong> (a note only, saving is never blocked)'
            .'<ul style="margin:.25rem 0 0 1rem;list-style:disc">'.$items.'</ul></div>';
    }
}
