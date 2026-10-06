<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AuthorizesResourceAccess;
use App\Filament\Resources\PageResource\Pages;
use App\Filament\Support\AuthoringGuidance;
use App\Filament\Support\BrandVoiceNote;
use App\Filament\Support\RawHtmlSection;
use App\Models\Page;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class PageResource extends Resource
{
    use AuthorizesResourceAccess;

    protected static ?string $model = Page::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationGroup = 'Content';

    protected static string $permissionKey = 'pages.manage';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('title')
                    ->required()
                    ->maxLength(255)
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (string $operation, $state, Forms\Set $set) => $operation === 'create' ? $set('slug', Str::slug($state)) : null),
                BrandVoiceNote::under('title'),
                Forms\Components\TextInput::make('slug')
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true),
                Forms\Components\RichEditor::make('content')
                    ->live(onBlur: true)
                    ->helperText(AuthoringGuidance::html('Any <h1> pasted in here is shown as an H2, because the page title is already the page\'s one H1. Link a page from the header or footer with Site Settings > Header / Footer, where it is a Page item.'))
                    ->columnSpanFull(),
                BrandVoiceNote::under('content'),
                RawHtmlSection::make('content'),

                Forms\Components\Section::make('SEO')
                    ->collapsible()
                    ->collapsed(fn (string $operation) => $operation === 'create')
                    ->schema([
                        Forms\Components\TextInput::make('meta_title')
                            ->label('Meta title')
                            ->live()
                            ->maxLength(70)
                            ->helperText(fn (?string $state): string => strlen($state ?? '').'/70 characters (recommended 50-60). Falls back to the page title if left blank.'),
                        Forms\Components\Textarea::make('meta_description')
                            ->label('Meta description')
                            ->live()
                            ->maxLength(160)
                            ->rows(2)
                            ->helperText(fn (?string $state): string => strlen($state ?? '').'/160 characters (recommended 120-155).'),
                        Forms\Components\TextInput::make('og_image')
                            ->label('Social share image URL')
                            ->url()
                            ->maxLength(255),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->searchable(),
                Tables\Columns\TextColumn::make('slug')
                    ->searchable(),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable(),
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
            'index' => Pages\ListPages::route('/'),
            'create' => Pages\CreatePage::route('/create'),
            'edit' => Pages\EditPage::route('/{record}/edit'),
        ];
    }
}
