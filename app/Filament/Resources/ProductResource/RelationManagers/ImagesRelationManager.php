<?php

namespace App\Filament\Resources\ProductResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class ImagesRelationManager extends RelationManager
{
    protected static string $relationship = 'images';

    private const MAX_IMAGES = 8;

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Placeholder::make('info')
                    ->label('')
                    ->content('The image with the lowest sort order is used as the main image everywhere on the storefront.')
                    ->columnSpanFull(),
                Forms\Components\FileUpload::make('url')
                    ->label('Image')
                    ->image()
                    ->disk('public')
                    ->directory('products')
                    ->imageEditor()
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                    ->maxSize(3072)
                    ->live()
                    ->afterStateUpdated(function ($state) {
                        if (! $state instanceof TemporaryUploadedFile) {
                            return;
                        }

                        $size = @getimagesize($state->getRealPath());
                        if (! $size) {
                            return;
                        }

                        [$width, $height] = $size;
                        $ratio = $width / max($height, 1);
                        $tooSmall = $width < 800 || $height < 800;
                        $notSquare = $ratio < 0.9 || $ratio > 1.1;

                        if ($tooSmall || $notSquare) {
                            Notification::make()
                                ->warning()
                                ->title('Image size heads-up')
                                ->body(
                                    "This image is {$width}×{$height}px. ".
                                    ($tooSmall ? 'For best quality on retina screens, at least 800×800px is recommended. ' : '').
                                    ($notSquare ? 'A roughly square image looks best in the gallery. ' : '').
                                    'It has still been uploaded -- this is just a heads-up, not a block.'
                                )
                                ->send();
                        }
                    })
                    ->required()
                    ->columnSpanFull(),
                Forms\Components\TextInput::make('alt_text')
                    ->label('Alt text')
                    ->helperText('Describe the image for accessibility and SEO.')
                    ->maxLength(255)
                    ->columnSpanFull(),
                Forms\Components\TextInput::make('sort_order')
                    ->numeric()
                    ->default(0)
                    ->required(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('url')
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->columns([
                Tables\Columns\ImageColumn::make('url')
                    ->label('Preview')
                    ->disk('public'),
                Tables\Columns\TextColumn::make('url')
                    ->limit(40)
                    ->searchable(),
                Tables\Columns\TextColumn::make('alt_text')
                    ->label('Alt text')
                    ->limit(40)
                    ->placeholder('None'),
                Tables\Columns\TextColumn::make('sort_order')
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label(fn (): string => 'Add image ('.$this->getOwnerRecord()->images()->count().'/'.self::MAX_IMAGES.')')
                    ->disabled(fn (): bool => $this->getOwnerRecord()->images()->count() >= self::MAX_IMAGES),
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
}
