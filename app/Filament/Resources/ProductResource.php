<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AuthorizesResourceAccess;
use App\Filament\Resources\ProductResource\Pages;
use App\Filament\Resources\ProductResource\RelationManagers;
use App\Filament\Support\AuthoringGuidance;
use App\Filament\Support\BrandVoiceNote;
use App\Filament\Support\MobileCards;
use App\Models\Category;
use App\Models\Product;
use App\Support\OrderItemPicture;
use App\Support\Pricing;
use App\Support\ProductThumbnail;
use App\Support\Sku;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
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
                    ->label('Primary category')
                    ->relationship('category', 'name')
                    ->searchable()
                    ->preload()
                    ->required()
                    ->live()
                    ->helperText('The primary category is used for the breadcrumb and feeds.')
                    // The primary is never also an extra.
                    ->afterStateUpdated(fn ($state, Get $get, Set $set) => $set('extra_category_ids', array_values(array_filter((array) $get('extra_category_ids'), fn ($id): bool => (int) $id !== (int) $state)))),
                // Not a column: saved to category_product by the page (Concerns\SavesExtraCategories).
                Forms\Components\Select::make('extra_category_ids')
                    ->label('Also show in these categories')
                    ->multiple()
                    ->searchable()
                    ->preload()
                    ->options(fn (Get $get): array => Category::query()->when(filled($get('category_id')), fn ($query) => $query->whereKeyNot((int) $get('category_id')))->orderBy('name')->pluck('name', 'id')->all())
                    ->dehydrated(false)
                    ->default([])
                    ->helperText('Optional. The product is also listed on these category pages.'),
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
                BrandVoiceNote::under('name'),
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
                BrandVoiceNote::under('summary'),

                Forms\Components\Section::make('Description')
                    ->schema([
                        Forms\Components\RichEditor::make('description')
                            ->label('')
                            ->live(onBlur: true)
                            ->helperText(AuthoringGuidance::html('Any <h1> pasted in here is automatically shown as an H2 on the storefront, so the page keeps exactly one true H1 -- the product name.'))
                            ->columnSpanFull(),
                        BrandVoiceNote::under('description'),

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
                    ->helperText('Set this lower than the regular price to show it as a Special price. Leave blank for no discount (never 0).')
                    ->numeric()
                    ->rules(fn (Get $get): array => [Pricing::formRule(fn () => $get('price'), 'Enter the regular price first, then a lower sale price.')])
                    ->suffix('৳'),
                Forms\Components\TextInput::make('stock_quantity')
                    ->required()
                    ->numeric()
                    ->minValue(0)
                    ->default(0),
                Forms\Components\TextInput::make('sku')
                    ->label('SKU')
                    ->required()
                    ->helperText('The id this product has in the product feeds and the ad pixels, for example ANZ-TS-001. A leading "SKU" word, extra spaces and the like are removed when you save.')
                    ->rules([fn (?Model $record): \Closure => function (string $attribute, mixed $value, \Closure $fail) use ($record): void {
                        if (($problem = Sku::problem($value, false, $record?->id)) !== null) {
                            $fail($problem);
                        }
                    }])
                    ->dehydrateStateUsing(fn (?string $state): string => Sku::clean($state)),
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
                Forms\Components\Toggle::make('exclude_from_feeds')
                    ->label('Exclude from product feeds')
                    ->helperText('Keeps this product out of the Google, Meta and TikTok feeds. It stays in the shop.'),

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

                Forms\Components\Section::make('In the box, and care')
                    ->description('Shown on the product page under "What is in the box?" and "How do I care for it?". A section with nothing in it is hidden.')
                    ->collapsible()
                    ->schema([
                        Forms\Components\Toggle::make('gift_box_included')
                            ->label('Gift box included')
                            ->helperText('When on, the price on the product page reads "৳1,450, gift box included". Leave it off unless a gift box really comes with this product.')
                            ->default(false)
                            ->columnSpanFull(),
                        Forms\Components\Repeater::make('box_contents')
                            ->label('What is in the box')
                            ->simple(
                                Forms\Components\TextInput::make('line')
                                    ->required()
                                    ->maxLength(120),
                            )
                            ->maxItems(12)
                            ->reorderable()
                            ->reorderableWithButtons()
                            ->addActionLabel('Add a line')
                            ->helperText('One short line each, for example "Teapot" or "Two cups". Up to 12.')
                            ->columnSpanFull(),
                        Forms\Components\Textarea::make('care_instructions')
                            ->label('Care instructions')
                            ->rows(3)
                            ->maxLength(400)
                            ->live(debounce: 500)
                            ->helperText(fn (?string $state): string => mb_strlen($state ?? '').'/400 characters. Plain text, for example how to wash it. Only write what is true for this product.')
                            ->columnSpanFull(),
                    ]),

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
        return MobileCards::phone($table
            ->modifyQueryUsing(fn ($query) => $query->with(['images', 'category', 'categories', 'variants.image']))
            ->columns([
                // A small square picture: the main one, else the rest of the gallery, else a variant's, else a neutral tile (App\Support\ProductThumbnail).
                Tables\Columns\ImageColumn::make('thumbnail')
                    ->label('')
                    ->state(fn (Product $record): ?string => ProductThumbnail::url($record))
                    ->defaultImageUrl(OrderItemPicture::placeholder())
                    ->size(48)
                    ->square()
                    ->extraImgAttributes(['loading' => 'lazy', 'alt' => '', 'style' => 'border-radius:2px;box-shadow:none;object-fit:cover;']),
                // The primary category, and a "+N" badge when the product is also listed in others (the tooltip names them).
                Tables\Columns\TextColumn::make('category.name')
                    ->label('Category')
                    ->sortable(),
                Tables\Columns\TextColumn::make('extra_categories')
                    ->label('')
                    ->badge()
                    ->color('gray')
                    ->state(fn (Product $record): ?string => self::extraCategoryBadge($record))
                    ->tooltip(fn (Product $record): ?string => self::extraCategoryNames($record) ?: null),
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
                // Matches ANY of a product's categories (primary or extra).
                Tables\Filters\SelectFilter::make('category_id')
                    ->label('Category')
                    ->options(fn (): array => Category::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->query(fn ($query, array $data) => filled($data['value'] ?? null) ? $query->inCategory((int) $data['value']) : $query),
                Tables\Filters\TernaryFilter::make('is_active'),
                Tables\Filters\TernaryFilter::make('is_new'),
                Tables\Filters\TernaryFilter::make('is_featured'),
            ])
            ->actions(MobileCards::actions(fn (): array => [
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]))
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    self::categoryBulkAction('add'),
                    self::categoryBulkAction('remove'),
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]), fn ($record): array => [
                ['left' => [MobileCards::picture(ProductThumbnail::url($record)), MobileCards::text($record->name, ['strong' => true, 'wrap' => true])], 'right' => [MobileCards::badge($record->is_active ? 'Active' : 'Hidden', $record->is_active ? 'success' : 'gray')]],
                ['left' => array_values(array_filter([MobileCards::text($record->sku, ['muted' => true]), MobileCards::text($record->category?->name, ['muted' => true]), self::extraCategoryBadge($record) ? MobileCards::badge(self::extraCategoryBadge($record)) : null]))],
                ['left' => [MobileCards::badge($record->stock_quantity > 0 ? 'In stock: '.$record->stock_quantity : 'Out of stock', $record->stock_quantity > 0 ? 'success' : 'danger')], 'right' => [MobileCards::text(MobileCards::taka($record->sale_price ?: $record->price), ['strong' => true])]],
            ]);
    }

    /** "+2" when the product is also listed in two other categories; null when it is only in its primary. */
    private static function extraCategoryBadge(Product $record): ?string
    {
        $extras = $record->categories->where('id', '!=', $record->category_id)->count();

        return $extras > 0 ? '+'.$extras : null;
    }

    /** "Gifts, Ceramic": the extra categories, for the tooltip. */
    private static function extraCategoryNames(Product $record): string
    {
        return $record->categories->where('id', '!=', $record->category_id)->pluck('name')->implode(', ');
    }

    /**
     * "Add to category" / "Remove from category" for the selected products. Only the extras are touched: a product's primary category is never added,
     * removed or changed here (a product already has it).
     */
    private static function categoryBulkAction(string $mode): Tables\Actions\BulkAction
    {
        $adding = $mode === 'add';

        return Tables\Actions\BulkAction::make($adding ? 'add_to_category' : 'remove_from_category')
            ->label($adding ? 'Add to category' : 'Remove from category')
            ->icon($adding ? 'heroicon-o-plus-circle' : 'heroicon-o-minus-circle')
            ->form([
                Forms\Components\Select::make('category_id')
                    ->label('Category')
                    ->options(fn (): array => Category::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->required()
                    ->helperText($adding ? 'The products are also listed here. Their primary category does not change.' : 'Takes the products out of this category. A product whose primary category it is keeps it.'),
            ])
            ->action(function (Collection $records, array $data) use ($adding): void {
                $category = Category::query()->find((int) $data['category_id']);

                if ($category === null) {
                    return;
                }

                $changed = 0;
                $primary = 0;

                foreach ($records as $record) {
                    if ((int) $record->category_id === $category->id) {
                        $primary++;

                        continue;
                    }

                    $adding ? $record->addCategories([$category->id]) : $record->removeCategories([$category->id]);
                    $changed++;
                }

                $notice = Notification::make()
                    ->title(($adding ? 'Added to "' : 'Removed from "').$category->name.'": '.$changed.' '.Str::plural('product', $changed))
                    ->success();

                if ($primary > 0) {
                    $notice->body($primary.' selected '.Str::plural('product', $primary).' left unchanged: "'.$category->name.'" is the primary category, which this action never touches.');
                }

                $notice->send();
            })
            ->deselectRecordsAfterCompletion();
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
