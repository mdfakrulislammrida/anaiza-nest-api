<?php

namespace App\Filament\Support;

use App\Support\ContentHtml;
use Filament\Forms\Components\Actions;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * "Paste or upload raw HTML instead" box. Neither input is saved itself: each puts what was supplied into the
 * target field, cleaned of what the shop never keeps (scripts, styles, classes), and switches the field to its HTML
 * box, so the text is stored as given and never passes through the visual editor. A note says what was removed.
 */
class RawHtmlSection
{
    public static function make(string $targetField): Section
    {
        $modeField = $targetField.'_mode';

        return Section::make('Paste or upload raw HTML instead')
            ->collapsible()
            ->collapsed()
            ->schema([
                Textarea::make('raw_html_paste')
                    ->label('Raw HTML')
                    ->dehydrated(false)
                    ->rows(6)
                    ->helperText('Paste HTML here, then click "Use this HTML". It replaces the text above and is kept as written; only scripts, styles and classes are removed. Tables are kept.')
                    ->columnSpanFull(),
                Actions::make([
                    Action::make('useHtml')
                        ->label('Use this HTML')
                        ->action(function (Get $get, Set $set) use ($targetField, $modeField) {
                            self::put((string) $get('raw_html_paste'), $targetField, $modeField, $set);
                        }),
                ]),
                FileUpload::make('raw_html_file')
                    ->label('...or upload an .html file')
                    ->dehydrated(false)
                    ->live()
                    ->acceptedFileTypes(['text/html'])
                    ->helperText('Uploading a file here immediately fills the text above with its contents, kept as written. Only the body of the file is used.')
                    ->afterStateUpdated(function ($state, Set $set) use ($targetField, $modeField) {
                        if ($state instanceof TemporaryUploadedFile) {
                            self::put($state->get(), $targetField, $modeField, $set);
                        }
                    })
                    ->columnSpanFull(),
            ])
            ->columnSpanFull();
    }

    /**
     * Cleans the supplied HTML, puts it in the field, opens the HTML box and says what was removed.
     */
    public static function put(string $html, string $targetField, string $modeField, Set $set): void
    {
        $result = ContentHtml::clean($html);

        $set($modeField, 'html');
        $set($targetField, $result['html']);

        if ($result['changed']) {
            Notification::make()
                ->title('HTML tidied')
                ->body(implode(' ', $result['removed']))
                ->warning()
                ->send();
        }
    }
}
