<?php

namespace App\Filament\Support;

use Filament\Forms\Components\Actions;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * "Paste or upload raw HTML instead" box, same behavior as the one on the
 * product and category forms: neither input is saved itself, each just
 * overwrites the target rich-editor field with exactly what was supplied.
 */
class RawHtmlSection
{
    public static function make(string $targetField): Section
    {
        return Section::make('Paste or upload raw HTML instead')
            ->collapsible()
            ->collapsed()
            ->schema([
                Textarea::make('raw_html_paste')
                    ->label('Raw HTML')
                    ->dehydrated(false)
                    ->rows(6)
                    ->helperText('Paste HTML here, then click "Use this HTML" to overwrite the content above with it exactly as typed.')
                    ->columnSpanFull(),
                Actions::make([
                    Action::make('useHtml')
                        ->label('Use this HTML')
                        ->action(function (Get $get, Set $set) use ($targetField) {
                            $set($targetField, $get('raw_html_paste'));
                        }),
                ]),
                FileUpload::make('raw_html_file')
                    ->label('...or upload an .html file')
                    ->dehydrated(false)
                    ->live()
                    ->acceptedFileTypes(['text/html'])
                    ->helperText('Uploading a file here immediately fills the content above with its contents.')
                    ->afterStateUpdated(function ($state, Set $set) use ($targetField) {
                        if ($state instanceof TemporaryUploadedFile) {
                            $set($targetField, $state->get());
                        }
                    })
                    ->columnSpanFull(),
            ])
            ->columnSpanFull();
    }
}
