<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AuthorizesResourceAccess;
use App\Filament\Resources\ProductResource\Pages;
use App\Filament\Resources\ProductResource\RelationManagers;
use App\Filament\Support\AuthoringGuidance;
use App\Models\Product;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class ProductResource extends Resource
{
    use AuthorizesResourceAccess;

    protected static ?string $model = Product::class;

    protected static ?string $navigationIcon = 'heroicon-o-shopping-bag';

    protected static ?string $navigationGroup = 'Catalog';

    protected static string $permissionKey = 'products.manage';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('category_id')
                    ->relationship('category', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),
                Forms\Components\Select::make('brand_id')
                    ->relationship('brand', 'name')
                    ->searchable()
                    ->preload(),
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->live()
                    ->maxLength(100)
                    ->helperText(fn (?string $state): string => strlen($state ?? '').'/100 characters (recommended 60-70). This becomes the page H1 -- put the main keyword first.')
                    ->afterStateUpdated(fn (string $operation, $state, Set $set) => $operation === 'create' ? $set('slug', Str::slug($state)) : null),
                Forms\Components\TextInput::make('slug')
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true),

                Forms\Components\Textarea::make('short_description')
                    ->label('Short description')
                    ->live()
                    ->maxLength(200)
                    ->rows(2)
                    ->helperText(fn (?string $state): string => strlen($state ?? '').'/200 characters. Plain text (no HTML) -- a short teaser shown near the price.')
                    ->columnSpanFull(),

                Forms\Components\Textarea::make('summary')
                    ->label('Summary (shown directly under the title)')
                    ->live()
                    ->maxLength(600)
                    ->rows(4)
                    ->helperText(fn (?string $state): string => mb_strlen($state ?? '').'/600 characters, '.AuthoringGuidance::wordCount($state).' words (recommended 50-100). Make the first sentence say what it is, who it is for and the key benefit, using the brand and product name instead of "our product".')
                    ->columnSpanFull(),

                Forms\Components\Section::make('Description')
                    ->schema([
                        Forms\Components\RichEditor::make('description')
                            ->label('')
                            ->helperText(AuthoringGuidance::html('Any <h1> pasted in here is automatically shown as an H2 on the storefront, so the page keeps exactly one true H1 -- the product name.'))
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
                                            $set('description', $get('raw_html_paste'));
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
                                            $set('description', $state->get());
                                        }
                                    })
                                    ->columnSpanFull(),
                            ])
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),

                Forms\Components\TextInput::make('price')
                    ->label('Price (BDT)')
                    ->helperText('Whole taka amount, no decimals')
                    ->required()
                    ->numeric()
                    ->minValue(0)
                    ->suffix('৳'),
                Forms\Components\TextInput::make('sale_price')
                    ->label('Sale price (BDT)')
                    ->helperText('Set this lower than the regular price to show it as a Special price. Leave blank for no discount.')
                    ->numeric()
                    ->minValue(0)
                    ->suffix('৳'),
                Forms\Components\TextInput::make('stock_quantity')
                    ->required()
                    ->numeric()
                    ->minValue(0)
                    ->default(0),
                Forms\Components\TextInput::make('sku')
                    ->label('SKU')
                    ->required()
                    ->unique(ignoreRecord: true),
                Forms\Components\TextInput::make('gtin')
                    ->label('GTIN / barcode (optional)')
                    ->regex('/^(\d{8}|\d{12,14})$/')
                    ->validationMessages(['regex' => 'A GTIN is 8, 12, 13 or 14 digits, with no spaces.'])
                    ->helperText('EAN/UPC barcode digits. Leave blank if the product has none -- it is only published when you fill it in.')
                    ->maxLength(14),
                Forms\Components\TextInput::make('mpn')
                    ->label('Manufacturer part number (optional)')
                    ->helperText('Leave blank if there is none -- it is only published when you fill it in.')
                    ->maxLength(100),
                Forms\Components\Toggle::make('is_new')
                    ->label('Mark as new arrival'),
                Forms\Components\Toggle::make('is_featured')
                    ->label('Show in Featured gifts'),
                Forms\Components\Toggle::make('is_active')
                    ->label('Active (visible in store)')
                    ->default(true),

                Forms\Components\Section::make('Merchandising')
                    ->collapsible()
                    ->schema([
                        Forms\Components\Select::make('tags')
                            ->relationship('tags', 'name')
                            ->multiple()
                            ->searchable()
                            ->preload(),
                        Forms\Components\Select::make('labels')
                            ->relationship('labels', 'name')
                            ->multiple()
                            ->searchable()
                            ->preload(),
                        Forms\Components\Select::make('attributeValues')
                            ->label('Attributes')
                            ->relationship('attributeValues', 'value')
                            ->getOptionLabelFromRecordUsing(fn ($record) => "{$record->attribute->name}: {$record->value}")
                            ->multiple()
                            ->searchable()
                            ->preload(),
                    ])
                    ->columnSpanFull(),

                Forms\Components\Section::make('Specifications')
                    ->description('Shown as a table on the product page. Up to 20 rows. A label such as "Material" or "Colour" is also published to search engines as that property.')
                    ->collapsible()
                    ->schema([
                        Forms\Components\Repeater::make('specifications')
                            ->label('')
                            ->schema([
                                Forms\Components\TextInput::make('label')
                                    ->required()
                                    ->maxLength(100)
                                    ->placeholder('e.g. Material'),
                                Forms\Components\TextInput::make('value')
                                    ->required()
                                    ->maxLength(255),
                            ])
                            ->columns(2)
                            ->maxItems(20)
                            ->reorderable()
                            ->reorderableWithButtons()
                            ->itemLabel(fn (array $state): ?string => $state['label'] ?? null)
                            ->addActionLabel('Add specification')
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),

                Forms\Components\Section::make('Product FAQ')
                    ->description('Shown as an accordion near the bottom of the product page. Up to 8 questions. '.AuthoringGuidance::faq())
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

                Forms\Components\Section::make('Video (optional)')
                    ->collapsible()
                    ->collapsed()
                    ->schema([
                        Forms\Components\TextInput::make('video_url')
                            ->label('YouTube / Vimeo URL')
                            ->url()
                            ->maxLength(255)
                            ->helperText('Takes priority over an uploaded MP4 below if both are set.'),
                        Forms\Components\FileUpload::make('video_file')
                            ->label('...or upload MP4')
                            ->disk('public')
                            ->directory('products/videos')
                            ->acceptedFileTypes(['video/mp4'])
                            ->maxSize(15360)
                            ->helperText('Max 15MB.'),
                        Forms\Components\FileUpload::make('video_poster')
                            ->label('Poster image')
                            ->image()
                            ->disk('public')
                            ->directory('products/videos')
                            ->helperText('Shown before the visitor taps play. Required for either video option above to actually display.'),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),

                Forms\Components\Section::make('SEO')
                    ->collapsible()
                    ->collapsed(fn (string $operation) => $operation === 'create')
                    ->schema([
                        Forms\Components\TextInput::make('meta_title')
                            ->label('Meta title')
                            ->live()
                            ->maxLength(70)
                            ->helperText(fn (?string $state): string => strlen($state ?? '').'/70 characters (recommended 50-60). Falls back to the product name if left blank.'),
                        Forms\Components\Textarea::make('meta_description')
                            ->label('Meta description')
                            ->live()
                            ->maxLength(160)
                            ->rows(2)
                            ->helperText(fn (?string $state): string => strlen($state ?? '').'/160 characters (recommended 120-155).'),
                        Forms\Components\FileUpload::make('og_image')
                            ->label('Social share image')
                            ->image()
                            ->disk('public')
                            ->directory('products/og'),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('category.name')
                    ->sortable(),
                Tables\Columns\TextColumn::make('brand.name')
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('sku')
                    ->label('SKU')
                    ->searchable(),
                Tables\Columns\TextColumn::make('price')
                    ->formatStateUsing(fn (int $state): string => '৳'.number_format($state))
                    ->sortable(),
                Tables\Columns\TextColumn::make('sale_price')
                    ->label('Sale price')
                    ->formatStateUsing(fn (?int $state): string => $state === null ? '—' : '৳'.number_format($state))
                    ->sortable(),
                Tables\Columns\TextColumn::make('stock_quantity')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_new')
                    ->boolean(),
                Tables\Columns\IconColumn::make('is_featured')
                    ->boolean(),
                Tables\Columns\IconColumn::make('is_active')
                    ->boolean(),
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
                Tables\Filters\SelectFilter::make('category_id')
                    ->relationship('category', 'name')
                    ->label('Category'),
                Tables\Filters\TernaryFilter::make('is_active'),
                Tables\Filters\TernaryFilter::make('is_new'),
                Tables\Filters\TernaryFilter::make('is_featured'),
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

    public static function getRelations(): array
    {
        return [
            RelationManagers\ImagesRelationManager::class,
            RelationManagers\VariantsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProducts::route('/'),
            'create' => Pages\CreateProduct::route('/create'),
            'edit' => Pages\EditProduct::route('/{record}/edit'),
        ];
    }
}
