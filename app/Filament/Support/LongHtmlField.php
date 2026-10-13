<?php

namespace App\Filament\Support;

use App\Support\ContentHtml;
use Filament\Forms\Components\Group;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\ToggleButtons;
use Filament\Forms\Get;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

/**
 * A long written field (a category's long description, a page, an article) that is either written in the visual editor
 * or kept as HTML. The two never share a screen, so nothing the visual editor cannot show (a table, a rule, a fourth
 * level of heading) is ever run through it and flattened.
 *
 * The column is one value either way; `{field}_mode` only chooses which box edits it, and is not saved. A record that
 * holds something the visual editor cannot show opens in the HTML box, and stays exactly as stored until it is edited.
 */
final class LongHtmlField
{
    public static function make(string $field, HtmlString|string|null $helper = null): Group
    {
        $mode = $field.'_mode';

        return Group::make([
            ToggleButtons::make($mode)
                ->label('Edit this text as')
                ->options(['visual' => 'Visual editor', 'html' => 'HTML'])
                ->inline()
                ->default('visual')
                ->live()
                ->dehydrated(false)
                ->disableOptionWhen(fn (string $value, Get $get): bool => $value === 'visual' && ContentHtml::needsHtmlEditor($get($field)))
                ->afterStateHydrated(function (ToggleButtons $component, ?Model $record) use ($field): void {
                    $component->state($record !== null && ContentHtml::needsHtmlEditor((string) $record->getAttribute($field)) ? 'html' : 'visual');
                })
                ->columnSpanFull(),

            Placeholder::make($field.'_html_note')
                ->hiddenLabel()
                ->content(new HtmlString(
                    '<strong>Contains a table, or formatting the visual editor cannot show.</strong> '.
                    'It is kept exactly as written; edit it in the HTML box below.'
                ))
                ->visible(fn (Get $get): bool => ContentHtml::needsHtmlEditor($get($field)))
                ->columnSpanFull(),

            RichEditor::make($field)
                ->label('')
                ->live(onBlur: true)
                ->visible(fn (Get $get): bool => $get($mode) !== 'html')
                ->helperText($helper)
                ->columnSpanFull(),

            Textarea::make($field)
                ->label('')
                ->live(onBlur: true)
                ->rows(18)
                ->extraInputAttributes(['spellcheck' => 'false', 'style' => 'font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: .8125rem'])
                ->visible(fn (Get $get): bool => $get($mode) === 'html')
                ->helperText($helper)
                ->columnSpanFull(),
        ])->columnSpanFull();
    }
}
