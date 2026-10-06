<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AuthorizesResourceAccess;
use App\Filament\Resources\HomepageSectionResource\Pages;
use App\Filament\Support\BrandVoiceNote;
use App\Models\Category;
use App\Models\HomepageSection;
use App\Models\Page;
use App\Support\SectionLinks;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class HomepageSectionResource extends Resource
{
    use AuthorizesResourceAccess;

    protected static ?string $model = HomepageSection::class;

    protected static ?string $navigationIcon = 'heroicon-o-view-columns';

    protected static ?string $navigationGroup = 'Content';

    protected static ?string $navigationLabel = 'Homepage Sections';

    protected static ?string $modelLabel = 'Homepage Section';

    protected static string $permissionKey = 'homepage_sections.manage';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('type')
                    ->options(HomepageSection::TYPES)
                    ->required()
                    ->live(),
                Forms\Components\TextInput::make('position')
                    ->numeric()
                    ->default(fn () => ((int) HomepageSection::max('position')) + 1)
                    ->required(),
                Forms\Components\Toggle::make('is_enabled')
                    ->label('Enabled')
                    ->default(true),
                Forms\Components\DateTimePicker::make('deal_ends_at')
                    ->label('Deal ends at (optional)')
                    ->helperText('Times are Bangladesh time. When set and still in the future, the storefront shows a live countdown to this moment. Leave blank for no countdown -- nothing is shown by default.')
                    ->timezone('Asia/Dhaka')
                    ->seconds(false)
                    ->visible(fn (Get $get): bool => $get('type') === 'hot_deals')
                    ->columnSpanFull(),
                Forms\Components\TextInput::make('custom_title')
                    ->live(onBlur: true)
                    ->label('Section title (optional)')
                    ->helperText(fn (Get $get): string => $get('type') === 'custom_html'
                        ? 'Shown as a heading above the HTML block -- leave blank for none.'
                        : 'Replaces the built-in title. Leave blank to keep the default ("Special prices", "Featured gifts", "New arrivals"...).')
                    ->maxLength(255)
                    ->visible(fn (Get $get): bool => in_array($get('type'), ['hot_deals', 'bestsellers', 'new_arrivals', 'newsletter', 'occasions', 'why_us', 'custom_html'], true))
                    ->columnSpanFull(),
                Forms\Components\TextInput::make('custom_subtitle')
                    ->live(onBlur: true)
                    ->label('Section subtitle (optional)')
                    ->helperText('The line under the title. Leave blank to keep the default.')
                    ->maxLength(255)
                    ->visible(fn (Get $get): bool => in_array($get('type'), ['hot_deals', 'bestsellers', 'new_arrivals', 'newsletter', 'occasions', 'why_us'], true))
                    ->columnSpanFull(),
                BrandVoiceNote::forFields(['custom_title' => 'Title', 'custom_subtitle' => 'Subtitle']),
                Forms\Components\Repeater::make('tiles')
                    ->label('Occasion tiles')
                    ->helperText('Shown two to a row on phones. A tile with no picture shows its label on a plain panel. Switched-off tiles and tiles with no link are not shown. "Add kit occasions" on the Homepage Sections list adds the seven kit occasions here, switched off.')
                    ->schema([
                        Forms\Components\TextInput::make('label')
                            ->required()
                            ->maxLength(60)
                            ->live(onBlur: true),
                        BrandVoiceNote::under('label'),
                        Forms\Components\Toggle::make('is_enabled')
                            ->label('Shown')
                            ->default(true),
                        Forms\Components\FileUpload::make('image')
                            ->label('Picture (optional)')
                            ->helperText('Any JPG, PNG or WebP. It is converted to a small WebP automatically.')
                            ->image()
                            ->disk('public')
                            ->directory('occasions')
                            ->imageEditor()
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                            ->maxSize(3072)
                            ->columnSpanFull(),
                        Forms\Components\Select::make('link_type')
                            ->label('Links to')
                            ->options(SectionLinks::TYPES)
                            ->default('category')
                            ->required()
                            ->native(false)
                            ->live(),
                        Forms\Components\Select::make('link_category')
                            ->label('Category')
                            ->options(fn (): array => Category::query()->orderBy('name')->pluck('name', 'slug')->all())
                            ->searchable()
                            ->required(fn (Get $get): bool => $get('link_type') === 'category')
                            ->visible(fn (Get $get): bool => $get('link_type') === 'category'),
                        Forms\Components\Select::make('link_page')
                            ->label('Page')
                            ->options(fn (): array => Page::query()->orderBy('title')->pluck('title', 'slug')->all())
                            ->searchable()
                            ->required(fn (Get $get): bool => $get('link_type') === 'page')
                            ->visible(fn (Get $get): bool => $get('link_type') === 'page'),
                        Forms\Components\TextInput::make('custom_url')
                            ->label('URL')
                            ->helperText('A path like /shop, or a full https:// address.')
                            ->maxLength(255)
                            ->required(fn (Get $get): bool => $get('link_type') === 'custom')
                            ->visible(fn (Get $get): bool => $get('link_type') === 'custom'),
                    ])
                    ->columns(2)
                    ->reorderable()
                    ->reorderableWithButtons()
                    ->collapsed()
                    ->itemLabel(fn (array $state): ?string => filled($state['label'] ?? null) ? $state['label'].(($state['is_enabled'] ?? true) ? '' : ' (switched off)') : null)
                    ->addActionLabel('Add a tile')
                    ->visible(fn (Get $get): bool => $get('type') === 'occasions')
                    ->columnSpanFull(),
                Forms\Components\Repeater::make('reasons')
                    ->label('Reasons')
                    ->helperText('Up to five. Each is a short title (optional) and one line. Empty by default, so the section stays hidden until you add something. "Insert kit lines" on the Homepage Sections list fills in the five lines from the brand kit.')
                    ->schema([
                        Forms\Components\TextInput::make('title')
                            ->label('Title (optional)')
                            ->maxLength(60)
                            ->live(onBlur: true),
                        Forms\Components\TextInput::make('line')
                            ->label('One line')
                            ->required()
                            ->maxLength(160)
                            ->live(onBlur: true),
                        BrandVoiceNote::forFields(['title' => 'Title', 'line' => 'Line']),
                    ])
                    ->columns(2)
                    ->maxItems(HomepageSection::MAX_REASONS)
                    ->reorderable()
                    ->reorderableWithButtons()
                    ->collapsed()
                    ->itemLabel(fn (array $state): ?string => filled($state['title'] ?? null) ? $state['title'] : ($state['line'] ?? null))
                    ->addActionLabel('Add a reason')
                    ->visible(fn (Get $get): bool => $get('type') === 'why_us')
                    ->columnSpanFull(),
                Forms\Components\Textarea::make('custom_html')
                    ->label('Custom HTML')
                    ->helperText('Raw HTML, rendered as-is on the homepage -- not sanitized. Only paste content you trust, since anyone with admin access could use this to inject anything.')
                    ->rows(12)
                    ->required(fn (Get $get): bool => $get('type') === 'custom_html')
                    ->visible(fn (Get $get): bool => $get('type') === 'custom_html')
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('position')
            ->reorderable('position')
            ->columns([
                Tables\Columns\TextColumn::make('type')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => HomepageSection::TYPES[$state] ?? $state),
                Tables\Columns\TextColumn::make('custom_title')
                    ->label('Custom Title')
                    ->placeholder('—')
                    ->toggleable(),
                Tables\Columns\ToggleColumn::make('is_enabled')
                    ->label('Enabled'),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_enabled')
                    ->label('Enabled'),
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
            'index' => Pages\ListHomepageSections::route('/'),
            'create' => Pages\CreateHomepageSection::route('/create'),
            'edit' => Pages\EditHomepageSection::route('/{record}/edit'),
        ];
    }
}
