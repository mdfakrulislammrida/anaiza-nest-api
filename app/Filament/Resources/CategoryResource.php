<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AuthorizesResourceAccess;
use App\Filament\Resources\CategoryResource\Pages;
use App\Filament\Support\AuthoringGuidance;
use App\Filament\Support\DeleteGuard;
use App\Models\Category;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class CategoryResource extends Resource
{
    use AuthorizesResourceAccess;

    protected static ?string $model = Category::class;

    protected static ?string $navigationIcon = 'heroicon-o-tag';

    protected static ?string $navigationGroup = 'Catalog';

    protected static string $permissionKey = 'categories.manage';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (string $operation, $state, Set $set) => $operation === 'create' ? $set('slug', Str::slug($state)) : null),
                Forms\Components\TextInput::make('slug')
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true),
                Forms\Components\Textarea::make('description')
                    ->helperText('Internal note -- not shown on the storefront. Use the intro text and SEO description below for that.')
                    ->columnSpanFull(),

                Forms\Components\Textarea::make('intro_text')
                    ->label('Summary / intro (shown directly under the title)')
                    ->live()
                    ->maxLength(600)
                    ->rows(4)
                    ->helperText(fn (?string $state): string => mb_strlen($state ?? '').'/600 characters, '.AuthoringGuidance::wordCount($state).' words (recommended 50-100). Make the first sentence say what this category is, who it is for and the key benefit, using the brand and category name instead of "our products".')
                    ->columnSpanFull(),

                Forms\Components\Section::make('Long SEO description')
                    ->description('Shown below the product grid, collapsed behind "Read more" on mobile. Typically 300-800 words.')
                    ->schema([
                        Forms\Components\RichEditor::make('seo_description')
                            ->label('')
                            ->helperText(AuthoringGuidance::html('Any <h1> pasted in here is automatically shown as an H2 on the storefront, so the page keeps exactly one true H1 -- the category name.'))
                            ->columnSpanFull(),

                        Forms\Components\Section::make('Paste or upload raw HTML instead')
                            ->collapsible()
                            ->collapsed()
                            ->schema([
                                Forms\Components\Textarea::make('raw_html_paste')
                                    ->label('Raw HTML')
                                    ->dehydrated(false)
                                    ->rows(6)
                                    ->helperText('Paste HTML here, then click "Use this HTML" to overwrite the description above with it exactly as typed.')
                                    ->columnSpanFull(),
                                Forms\Components\Actions::make([
                                    Forms\Components\Actions\Action::make('useHtml')
                                        ->label('Use this HTML')
                                        ->action(function (Get $get, Set $set) {
                                            $set('seo_description', $get('raw_html_paste'));
                                        }),
                                ]),
                                Forms\Components\FileUpload::make('raw_html_file')
                                    ->label('...or upload an .html file')
                                    ->dehydrated(false)
                                    ->live()
                                    ->acceptedFileTypes(['text/html'])
                                    ->helperText('Uploading a file here immediately fills the description above with its contents.')
                                    ->afterStateUpdated(function ($state, Set $set) {
                                        if ($state instanceof TemporaryUploadedFile) {
                                            $set('seo_description', $state->get());
                                        }
                                    })
                                    ->columnSpanFull(),
                            ])
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),

                Forms\Components\Section::make('Banners & thumbnail')
                    ->collapsible()
                    ->schema([
                        Forms\Components\FileUpload::make('banner_desktop')
                            ->label('Desktop banner')
                            ->image()
                            ->disk('public')
                            ->directory('categories')
                            ->live()
                            ->afterStateUpdated(self::dimensionWarning(1600, 400, 'desktop banner'))
                            ->helperText('Recommended 1600×400 (4:1).'),
                        Forms\Components\FileUpload::make('banner_mobile')
                            ->label('Mobile banner')
                            ->image()
                            ->disk('public')
                            ->directory('categories')
                            ->live()
                            ->afterStateUpdated(self::dimensionWarning(750, 500, 'mobile banner'))
                            ->helperText('Recommended 750×500 (3:2). Leave blank to auto-generate a crop from the desktop banner.'),
                        Forms\Components\FileUpload::make('thumbnail')
                            ->label('Tile / thumbnail')
                            ->image()
                            ->disk('public')
                            ->directory('categories')
                            ->live()
                            ->afterStateUpdated(self::dimensionWarning(600, 600, 'thumbnail'))
                            ->helperText('Recommended 600×600 (1:1). Used in nav/category grids elsewhere on the site.'),
                    ])
                    ->columns(3)
                    ->columnSpanFull(),

                Forms\Components\Section::make('Category FAQ')
                    ->description('Shown as an accordion near the bottom of the category page. Up to 8 questions. '.AuthoringGuidance::faq())
                    ->collapsible()
                    ->schema([
                        Forms\Components\Repeater::make('faqs')
                            ->relationship('faqs')
                            ->label('')
                            ->schema([
                                Forms\Components\TextInput::make('question')
                                    ->required()
                                    ->live()
                                    ->maxLength(120)
                                    ->helperText(fn (?string $state): string => strlen($state ?? '').'/120 characters'),
                                Forms\Components\Textarea::make('answer')
                                    ->required()
                                    ->live()
                                    ->maxLength(500)
                                    ->rows(3)
                                    ->helperText(fn (?string $state): string => strlen($state ?? '').'/500 characters'),
                            ])
                            ->maxItems(8)
                            ->reorderable()
                            ->reorderableWithButtons()
                            ->collapsed()
                            ->itemLabel(fn (array $state): ?string => $state['question'] ?? null)
                            ->addActionLabel('Add FAQ')
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),

                Forms\Components\Section::make('SEO')
                    ->collapsible()
                    ->collapsed(fn (string $operation) => $operation === 'create')
                    ->schema([
                        Forms\Components\TextInput::make('meta_title')
                            ->label('Meta title')
                            ->live()
                            ->maxLength(70)
                            ->helperText(fn (?string $state): string => strlen($state ?? '').'/70 characters (recommended 50-60). Falls back to the category name if left blank.'),
                        Forms\Components\Textarea::make('meta_description')
                            ->label('Meta description')
                            ->live()
                            ->maxLength(160)
                            ->rows(2)
                            ->helperText(fn (?string $state): string => strlen($state ?? '').'/160 characters (recommended 120-155).'),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    /**
     * A non-blocking heads-up when an uploaded image's aspect ratio is very
     * different from the recommended one -- mirrors ImagesRelationManager's
     * product-image warning, generalized for an arbitrary target ratio.
     */
    private static function dimensionWarning(int $recommendedWidth, int $recommendedHeight, string $label): \Closure
    {
        return function ($state) use ($recommendedWidth, $recommendedHeight, $label) {
            if (! $state instanceof TemporaryUploadedFile) {
                return;
            }

            $size = @getimagesize($state->getRealPath());
            if (! $size) {
                return;
            }

            [$width, $height] = $size;
            $targetRatio = $recommendedWidth / $recommendedHeight;
            $actualRatio = $width / max($height, 1);
            $offRatio = abs($actualRatio - $targetRatio) / $targetRatio > 0.15;

            if ($offRatio) {
                Notification::make()
                    ->warning()
                    ->title('Image size heads-up')
                    ->body(
                        "This {$label} is {$width}×{$height}px. Recommended is around {$recommendedWidth}×{$recommendedHeight}px. ".
                        'It has still been uploaded -- this is just a heads-up, not a block.'
                    )
                    ->send();
            }
        };
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('thumbnail')
                    ->label('')
                    ->disk('public'),
                Tables\Columns\TextColumn::make('name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('slug')
                    ->searchable(),
                Tables\Columns\TextColumn::make('products_count')
                    ->counts('products')
                    ->label('Products'),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()->using(DeleteGuard::single(['products' => 'products'], 'Move them to another category or delete them first.')),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->using(DeleteGuard::bulk(['products' => 'products'], 'Move them to another category or delete them first.'))
                        ->successNotificationTitle(null),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCategories::route('/'),
            'create' => Pages\CreateCategory::route('/create'),
            'edit' => Pages\EditCategory::route('/{record}/edit'),
        ];
    }
}
