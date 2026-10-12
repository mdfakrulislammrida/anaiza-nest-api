<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AuthorizesResourceAccess;
use App\Filament\Resources\LandingPageResource\Pages;
use App\Filament\Support\BrandVoiceNote;
use App\Models\Category;
use App\Models\Faq;
use App\Models\LandingPage;
use App\Models\Product;
use App\Support\Landing\LandingBlocks;
use App\Support\Landing\LandingStats;
use Filament\Forms;
use Filament\Forms\Components\Builder;
use Filament\Forms\Components\Builder\Block;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class LandingPageResource extends Resource
{
    use AuthorizesResourceAccess;

    protected static ?string $model = LandingPage::class;

    protected static ?string $navigationIcon = 'heroicon-o-megaphone';

    protected static ?string $navigationGroup = 'Content';

    protected static ?string $navigationLabel = 'Landing pages';

    protected static ?string $modelLabel = 'landing page';

    protected static string $permissionKey = 'landing_pages.manage';

    /** @var array<string, string> */
    public const BENEFIT_ICONS = [
        'delivery' => 'Delivery',
        'cash' => 'Cash on delivery',
        'gift' => 'Gift wrapping',
        'packed' => 'Packed by hand',
        'secure' => 'Secure',
        'support' => 'Help when you need it',
        'check' => 'Tick',
    ];

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Page')
                ->schema([
                    Forms\Components\TextInput::make('title')
                        ->required()
                        ->maxLength(255)
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (string $operation, $state, Set $set) => $operation === 'create' ? $set('slug', Str::slug((string) $state)) : null),
                    Forms\Components\TextInput::make('slug')
                        ->label('Address')
                        ->prefix('/lp/')
                        ->required()
                        ->maxLength(120)
                        ->alphaDash()
                        ->unique(ignoreRecord: true)
                        ->helperText('Lower-case letters, numbers and dashes. The page opens at your shop address followed by /lp/ and this.'),
                    Forms\Components\Select::make('status')
                        ->options(['draft' => 'Draft (only you can see it, with the preview link)', 'published' => 'Published'])
                        ->default('draft')
                        ->required()
                        ->native(false),
                    Forms\Components\Radio::make('layout')
                        ->options([
                            'focus' => 'Focus mode: logo only, no menu and no footer links, so visitors stay on the page',
                            'normal' => 'Normal: the shop\'s header and footer',
                        ])
                        ->default('focus')
                        ->required(),
                    Forms\Components\DateTimePicker::make('publish_from')
                        ->label('Opens at (Dhaka time, optional)')
                        ->timezone('Asia/Dhaka')
                        ->seconds(false)
                        ->helperText('Leave blank to open as soon as it is published.'),
                    Forms\Components\DateTimePicker::make('publish_until')
                        ->label('Closes at (Dhaka time, optional)')
                        ->timezone('Asia/Dhaka')
                        ->seconds(false)
                        ->after('publish_from')
                        ->helperText('After this the page says "This offer has ended" and links to the shop. A countdown block counts down to exactly this time.'),
                ])->columns(2),

            Forms\Components\Section::make('Search and sharing')
                ->collapsible()
                ->collapsed()
                ->schema([
                    Forms\Components\Toggle::make('noindex')
                        ->label('Keep this page out of search engines (and the sitemap)')
                        ->default(true)
                        ->helperText('On is right for an ad destination. Switch it off only if you want people to find this page through Google; it is then included in the sitemap after the next site build.'),
                    Forms\Components\TextInput::make('seo_title')->label('Title in search results and when shared')->maxLength(70)
                        ->helperText('Falls back to the page title.'),
                    Forms\Components\Textarea::make('meta_description')->label('Description')->rows(2)->maxLength(255),
                    Forms\Components\FileUpload::make('og_image')
                        ->label('Picture when shared')
                        ->image()
                        ->disk('public')
                        ->directory('landing-pages')
                        ->maxSize(4096),
                    Forms\Components\TextInput::make('canonical')
                        ->label('Canonical address (optional)')
                        ->url()
                        ->maxLength(255)
                        ->helperText('Only when this page repeats another page and you want search engines to treat that one as the original.'),
                ])->columns(2),

            Forms\Components\Placeholder::make('notes_report')
                ->label('What the last save changed in your HTML')
                ->visible(fn (?LandingPage $record): bool => filled($record?->notes))
                ->content(fn (?LandingPage $record): HtmlString => new HtmlString(
                    '<ul class="list-disc space-y-1 pl-5 text-sm">'.collect($record?->notes ?? [])->map(
                        fn (string $line): string => '<li class="'.(str_contains($line, 'WARNING') ? 'font-semibold text-danger-600' : '').'">'.e($line).'</li>'
                    )->implode('').'</ul>'
                ))
                ->columnSpanFull(),

            Builder::make('blocks')
                ->label('Content')
                ->helperText('Add blocks, drag them into order, and switch a block off with "Show this block" to hide it without deleting it.')
                ->blocks(self::blocks())
                ->collapsible()
                ->cloneable()
                ->reorderable()
                ->blockNumbers(false)
                ->addActionLabel('Add a block')
                ->columnSpanFull(),
        ]);
    }

    /**
     * @return list<Block>
     */
    private static function blocks(): array
    {
        $visible = fn () => Forms\Components\Toggle::make('is_visible')->label('Show this block')->default(true);

        return [
            Block::make('hero')->label(fn (?array $state): string => self::label('Hero', $state, 'headline'))->icon('heroicon-o-photo')->schema([
                $visible(),
                Forms\Components\TextInput::make('headline')->maxLength(160)->live(onBlur: true),
                BrandVoiceNote::under('headline'),
                Forms\Components\Textarea::make('subline')->rows(2)->maxLength(300)->live(onBlur: true),
                BrandVoiceNote::under('subline'),
                Forms\Components\FileUpload::make('image_desktop')->label('Picture, desktop')->image()->disk('public')->directory('landing-pages')->maxSize(4096),
                Forms\Components\FileUpload::make('image_mobile')->label('Picture, mobile (optional)')->image()->disk('public')->directory('landing-pages')->maxSize(4096),
                Forms\Components\TextInput::make('button_text')->maxLength(60)->placeholder('Order now, pay on delivery'),
                Forms\Components\Select::make('button_target')->label('Button goes to')->options([
                    'products' => 'The products',
                    'order' => 'The order form',
                    'url' => 'An address',
                ])->default('products')->live()->native(false),
                Forms\Components\TextInput::make('button_url')->label('Address')->maxLength(255)
                    ->visible(fn (Get $get): bool => $get('button_target') === 'url'),
            ]),

            Block::make('rich_text')->label(fn (?array $state): string => self::label('Text', $state, null))->icon('heroicon-o-document-text')->schema([
                $visible(),
                Forms\Components\RichEditor::make('content')->label('Text')->required()->live(onBlur: true),
                BrandVoiceNote::under('content'),
            ]),

            Block::make('custom_html')->label(fn (?array $state): string => self::label('Custom HTML', $state, null))->icon('heroicon-o-code-bracket')->schema([
                $visible(),
                Forms\Components\Textarea::make('html')
                    ->label('HTML')
                    ->rows(14)
                    ->maxLength(LandingBlocks::MAX_HTML_BYTES)
                    ->helperText('Paste HTML, or upload a file below. When you save, scripts, inline event handlers, javascript: links, forms, other sites\' stylesheets and iframes other than YouTube and Vimeo are taken out, and a list of what was removed appears at the top of this page.'),
                Forms\Components\FileUpload::make('html_file')
                    ->label('...or upload an .html or .htm file (up to 1 MB)')
                    ->dehydrated(false)
                    ->live()
                    ->acceptedFileTypes(['text/html'])
                    ->maxSize(1024)
                    ->rules(['extensions:html,htm'])
                    ->helperText('The file\'s style tags and everything inside its body are put into the box above so you can still edit them. Links to stylesheets in its head are not brought over.')
                    ->afterStateUpdated(function ($state, Set $set): void {
                        if ($state instanceof TemporaryUploadedFile) {
                            $set('html', LandingBlocks::importHtmlFile((string) $state->get()));
                        }
                    }),
                Forms\Components\FileUpload::make('assets')
                    ->label('Images used by this HTML')
                    ->multiple()
                    ->image()
                    ->disk('public')
                    ->directory('media-library/landing')
                    ->preserveFilenames()
                    ->maxSize(4096)
                    ->helperText('Upload the pictures your HTML refers to (images/photo.jpg). Where the file name matches, the path in your HTML is pointed at the upload; any it cannot match are listed after saving. They are added to the media library.'),
                Forms\Components\Toggle::make('allow_scripts')
                    ->label('Allow scripts in this HTML')
                    ->disabled(fn (): bool => ! LandingBlocks::canAllowScripts(Auth::user()))
                    ->helperText(fn (): string => LandingBlocks::canAllowScripts(Auth::user())
                        ? 'RISKY: every visitor\'s browser runs these scripts. Never available on a Focus-mode page that has the order form.'
                        : 'You do not have permission to allow scripts. Ask a super-admin.'),
            ]),

            Block::make('products')->label(fn (?array $state): string => self::label('Products', $state, 'heading'))->icon('heroicon-o-shopping-bag')->schema([
                $visible(),
                Forms\Components\TextInput::make('heading')->maxLength(120),
                Forms\Components\Radio::make('source')->options(['hand' => 'Pick products by hand', 'category' => 'All products of a category'])->default('hand')->live(),
                Forms\Components\Select::make('product_ids')
                    ->label('Products')
                    ->multiple()
                    ->searchable()
                    ->maxItems(24)
                    ->getSearchResultsUsing(fn (string $search): array => Product::query()
                        ->where('is_active', true)
                        ->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('sku', 'like', "%{$search}%"))
                        ->orderBy('name')->limit(30)->pluck('name', 'id')->all())
                    ->getOptionLabelsUsing(fn (array $values): array => Product::query()->whereIn('id', $values)->pluck('name', 'id')->all())
                    ->visible(fn (Get $get): bool => ($get('source') ?? 'hand') === 'hand'),
                Forms\Components\Select::make('category_id')
                    ->label('Category')
                    ->options(fn (): array => Category::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->visible(fn (Get $get): bool => $get('source') === 'category'),
                Forms\Components\TextInput::make('limit')->label('Most products to show')->numeric()->default(12)->minValue(1)->maxValue(48)
                    ->visible(fn (Get $get): bool => $get('source') === 'category'),
                Forms\Components\Radio::make('layout')->options(['grid' => 'Grid', 'list' => 'List'])->default('grid')->inline(),
                Forms\Components\Toggle::make('show_price')->label('Show the price and the sale price')->default(true),
                Forms\Components\Toggle::make('show_button')->label('Show the "Order now, pay on delivery" button')->default(true),
                Forms\Components\Toggle::make('picker')->label('Let visitors choose quantity and option (colour, size)')->default(true),
            ]),

            Block::make('benefits')->label(fn (?array $state): string => self::label('Benefits row', $state, 'heading'))->icon('heroicon-o-check-badge')->schema([
                $visible(),
                Forms\Components\TextInput::make('heading')->maxLength(120),
                Forms\Components\Repeater::make('items')->schema([
                    Forms\Components\Select::make('icon')->options(self::BENEFIT_ICONS)->default('check')->native(false),
                    Forms\Components\TextInput::make('text')->required()->maxLength(80)->live(onBlur: true),
                    BrandVoiceNote::under('text'),
                ])->defaultItems(3)->maxItems(6)->addActionLabel('Add a line')->columns(2),
            ]),

            Block::make('faq')->label(fn (?array $state): string => self::label('FAQ', $state, 'heading'))->icon('heroicon-o-question-mark-circle')->schema([
                $visible(),
                Forms\Components\TextInput::make('heading')->maxLength(120),
                Forms\Components\Select::make('faq_ids')
                    ->label('Questions')
                    ->multiple()
                    ->options(fn (): array => Faq::query()->orderBy('sort_order')->pluck('question', 'id')->all())
                    ->helperText('Leave empty to show every question from Content > FAQs. The same markup and FAQ schema as the FAQ page is used.'),
            ]),

            Block::make('reviews')->label(fn (?array $state): string => self::label('Reviews', $state, 'heading'))->icon('heroicon-o-star')->schema([
                $visible(),
                Forms\Components\TextInput::make('heading')->maxLength(120),
                Forms\Components\TextInput::make('limit')->label('Most reviews to show')->numeric()->default(6)->minValue(1)->maxValue(20),
                Forms\Components\Placeholder::make('reviews_note')->hiddenLabel()
                    ->content('Real, approved reviews of the products on this page. The block is hidden while there are none.'),
            ]),

            Block::make('order_form')->label(fn (?array $state): string => self::label('Order form', $state, 'heading'))->icon('heroicon-o-clipboard-document-check')->schema([
                $visible(),
                Forms\Components\TextInput::make('heading')->maxLength(120)->placeholder('Your order'),
                Forms\Components\Textarea::make('intro')->rows(2)->maxLength(300)->live(onBlur: true),
                BrandVoiceNote::under('intro'),
                Forms\Components\Placeholder::make('order_note')->hiddenLabel()
                    ->content('The same checkout the shop uses: delivery area, cash on delivery and wallets, coupon, stock check and protections. Orders are tagged with this page.'),
            ]),

            Block::make('countdown')->label(fn (?array $state): string => self::label('Offer countdown', $state, 'label'))->icon('heroicon-o-clock')->schema([
                $visible(),
                Forms\Components\TextInput::make('label')->maxLength(80)->placeholder('This offer ends in'),
                Forms\Components\Placeholder::make('countdown_note')->hiddenLabel()
                    ->content('Counts down to the page\'s "Closes at" time. Without one, or once it has passed, nothing is shown: there is never a made-up timer.'),
            ]),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $state
     */
    private static function label(string $name, ?array $state, ?string $field): string
    {
        $text = $field !== null ? trim((string) ($state[$field] ?? '')) : '';
        $hidden = ($state['is_visible'] ?? true) === false ? ' (hidden)' : '';

        return $name.($text !== '' ? ': '.Str::limit($text, 40) : '').$hidden;
    }

    // ---- stats for the list

    /**
     * @return array{views: int, add_to_carts: int, orders: int, conversion: ?float, revenue: int}
     */
    public static function statsFor(LandingPage $page, $livewire): array
    {
        static $memo = [];

        $range = (array) ($livewire->tableFilters['range'] ?? []);
        $from = filled($range['from'] ?? null) ? Carbon::parse($range['from'], 'Asia/Dhaka') : null;
        $until = filled($range['until'] ?? null) ? Carbon::parse($range['until'], 'Asia/Dhaka') : null;
        $key = $page->id.'|'.($from?->toDateString() ?? '').'|'.($until?->toDateString() ?? '').'|'.$page->updated_at?->timestamp;

        return $memo[$key] ??= LandingStats::forPage($page, $from, $until);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('updated_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('title')->searchable()->description(fn (LandingPage $record): string => '/lp/'.$record->slug),
                Tables\Columns\TextColumn::make('state')
                    ->label('State')
                    ->badge()
                    ->state(fn (LandingPage $record): string => $record->state())
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'live' => 'Live',
                        'ended' => 'Ended',
                        'scheduled' => 'Scheduled',
                        default => 'Draft',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'live' => 'success',
                        'ended' => 'gray',
                        'scheduled' => 'warning',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('window')->label('Open')->state(fn (LandingPage $record): string => $record->windowLabel())->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('views')->label('Views')->state(fn (LandingPage $record, $livewire): int => self::statsFor($record, $livewire)['views'])->numeric(),
                Tables\Columns\TextColumn::make('add_to_carts')->label('Added to cart')->state(fn (LandingPage $record, $livewire): int => self::statsFor($record, $livewire)['add_to_carts'])->numeric(),
                Tables\Columns\TextColumn::make('orders')->label('Orders')->state(fn (LandingPage $record, $livewire): int => self::statsFor($record, $livewire)['orders'])->numeric(),
                Tables\Columns\TextColumn::make('conversion')->label('Conversion')
                    ->state(fn (LandingPage $record, $livewire): string => ($rate = self::statsFor($record, $livewire)['conversion']) === null ? '—' : $rate.'%'),
                Tables\Columns\TextColumn::make('revenue')->label('Revenue')
                    ->state(fn (LandingPage $record, $livewire): string => '৳'.number_format(self::statsFor($record, $livewire)['revenue'])),
                Tables\Columns\IconColumn::make('noindex')->label('Hidden from search')->boolean()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\Filter::make('range')
                    ->label('Stats for')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('From')->default(fn () => Carbon::now('Asia/Dhaka')->subDays(29)->toDateString()),
                        Forms\Components\DatePicker::make('until')->label('Until')->default(fn () => Carbon::now('Asia/Dhaka')->toDateString()),
                    ])
                    ->columns(2)
                    // Only chooses the dates the stat columns count over: it never hides a page.
                    ->query(fn ($query) => $query),
            ])
            ->actions([
                Tables\Actions\Action::make('open')
                    ->label(fn (LandingPage $record): string => $record->state() === 'live' ? 'Open' : 'Preview')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (LandingPage $record): string => $record->state() === 'live' ? $record->url() : $record->previewUrl())
                    ->openUrlInNewTab(),
                Tables\Actions\Action::make('duplicate')
                    ->label('Duplicate')
                    ->icon('heroicon-o-document-duplicate')
                    ->requiresConfirmation()
                    ->modalDescription('Makes a draft copy you can change for a new campaign. Nothing is shared with the original.')
                    ->action(function (LandingPage $record): void {
                        $copy = self::duplicate($record);

                        Notification::make()->success()->title('Copy made')->body('It is a draft at /lp/'.$copy->slug.'.')->send();
                    }),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function duplicate(LandingPage $page): LandingPage
    {
        $base = Str::limit($page->slug, 100, '').'-copy';
        $slug = $base;

        for ($n = 2; LandingPage::query()->where('slug', $slug)->exists(); $n++) {
            $slug = $base.'-'.$n;
        }

        $copy = $page->replicate(['created_at', 'updated_at']);
        $copy->title = $page->title.' (copy)';
        $copy->slug = $slug;
        $copy->status = LandingPage::DRAFT;
        // Each HTML block gets its own wrapper name, so the two pages never share a stylesheet scope.
        $copy->blocks = collect($page->blocks ?? [])->map(function (array $block): array {
            unset($block['data']['uid']);

            return $block;
        })->all();
        $copy->save();

        return $copy;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLandingPages::route('/'),
            'create' => Pages\CreateLandingPage::route('/create'),
            'edit' => Pages\EditLandingPage::route('/{record}/edit'),
        ];
    }
}
