<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AuthorizesResourceAccess;
use App\Filament\Resources\BannerResource\Pages;
use App\Models\Banner;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class BannerResource extends Resource
{
    use AuthorizesResourceAccess;

    protected static ?string $model = Banner::class;

    protected static ?string $navigationIcon = 'heroicon-o-photo';

    protected static ?string $navigationGroup = 'Content';

    protected static ?string $navigationLabel = 'Banners / Sliders';

    protected static ?string $modelLabel = 'Slide';

    protected static string $permissionKey = 'banners.manage';

    /**
     * The slider shows five slides at most, so a sixth is not offered rather than quietly never shown.
     */
    public static function canCreate(): bool
    {
        return parent::canCreate() && Banner::count() < Banner::MAX_SLIDES;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Pictures')
                    ->schema([
                        Forms\Components\FileUpload::make('image_url')
                            ->label('Desktop image')
                            ->image()
                            ->disk('public')
                            ->directory('banners')
                            ->required()
                            ->live()
                            ->afterStateUpdated(CategoryResource::dimensionWarning(1920, 800, 'desktop image'))
                            ->helperText('Made for 1920×800 (12:5). The shop makes smaller WebP copies by itself.'),
                        Forms\Components\FileUpload::make('mobile_image')
                            ->label('Mobile image (optional)')
                            ->image()
                            ->disk('public')
                            ->directory('banners')
                            ->live()
                            ->afterStateUpdated(CategoryResource::dimensionWarning(750, 500, 'mobile image'))
                            ->helperText('Recommended 750×500 (3:2). Leave blank and the shop crops the middle of the desktop image for phones.'),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('Words and link')
                    ->schema([
                        Forms\Components\TextInput::make('headline')
                            ->maxLength(120),
                        Forms\Components\TextInput::make('subtext')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('link')
                            ->label('Link (optional)')
                            ->helperText('A full address, or a path on the shop such as /category/gifts. Leave blank for a slide that does not link.')
                            ->maxLength(255)
                            ->rule(fn () => function (string $attribute, $value, \Closure $fail) {
                                if (filled($value) && ! preg_match('#^(https?://|/)#i', trim((string) $value))) {
                                    $fail('Start the link with https:// or with / for a page on the shop.');
                                }
                            }),
                        Forms\Components\TextInput::make('button_text')
                            ->label('Button text (optional)')
                            ->helperText('Shown as a button on the slide when it has a link. Leave blank for no button: the whole slide still links.')
                            ->maxLength(40),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('When it shows')
                    ->schema([
                        Forms\Components\DateTimePicker::make('starts_at')
                            ->label('Starts (optional)')
                            ->seconds(false),
                        Forms\Components\DateTimePicker::make('ends_at')
                            ->label('Ends (optional)')
                            ->seconds(false)
                            ->after('starts_at'),
                        Forms\Components\TextInput::make('sort_order')
                            ->label('Order')
                            ->helperText('Smaller numbers come first.')
                            ->numeric()
                            ->integer()
                            ->minValue(0)
                            ->default(0)
                            ->required(),
                        Forms\Components\Toggle::make('is_active')
                            ->label('On')
                            ->default(true),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->columns([
                Tables\Columns\ImageColumn::make('image_url')
                    ->label('Preview')
                    ->disk('public'),
                Tables\Columns\TextColumn::make('headline')
                    ->searchable(),
                Tables\Columns\TextColumn::make('sort_order')
                    ->label('Order')
                    ->sortable(),
                Tables\Columns\TextColumn::make('starts_at')
                    ->label('Starts')
                    ->dateTime('j M Y, H:i')
                    ->placeholder('Straight away')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('ends_at')
                    ->label('Ends')
                    ->dateTime('j M Y, H:i')
                    ->placeholder('No end')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Showing')
                    ->state(fn (Banner $record): string => match (true) {
                        ! $record->is_active => 'Off',
                        $record->starts_at && $record->starts_at->isFuture() => 'Not yet',
                        $record->ends_at && $record->ends_at->isPast() => 'Ended',
                        default => 'Live',
                    })
                    ->badge()
                    ->color(fn (string $state): string => $state === 'Live' ? 'success' : 'gray'),
                Tables\Columns\IconColumn::make('is_active')
                    ->label('On')
                    ->boolean(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('On'),
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
            'index' => Pages\ListBanners::route('/'),
            'create' => Pages\CreateBanner::route('/create'),
            'edit' => Pages\EditBanner::route('/{record}/edit'),
        ];
    }
}
