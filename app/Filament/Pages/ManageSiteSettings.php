<?php

namespace App\Filament\Pages;

use App\Filament\Support\BrandVoiceNote;
use App\Models\Category;
use App\Models\Page as CmsPage;
use App\Models\SiteSetting;
use App\Support\BrandVoice;
use App\Support\MenuLinks;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

class ManageSiteSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Site Settings';

    protected static ?string $title = 'Site Settings';

    protected static string $view = 'filament.pages.manage-site-settings';

    protected static ?string $slug = 'site-settings';

    /**
     * The wording fields the brand check reads (state path => label).
     *
     * @var array<string, string>
     */
    private const VOICE_FIELDS = [
        'hero_badge' => 'Hero badge',
        'hero_title' => 'Hero headline',
        'hero_text' => 'Hero paragraph',
        'hero_hot_deals_text' => 'Special prices tile',
        'hero_new_arrivals_text' => 'New arrivals tile',
        'newsletter_headline' => 'Newsletter headline',
        'newsletter_text' => 'Newsletter text',
        'promo_text' => 'Promo bar',
        'footer_about' => 'Footer about',
        'brand_description' => 'Brand description',
        'corporate_intro' => 'Corporate intro',
    ];

    /**
     * Paths offered as suggestions in the menu and footer link pickers.
     *
     * @var list<string>
     */
    private const LINK_SUGGESTIONS = ['/shop', '/hot-deals', '/gift-finder', '/corporate-gifting', '/contact', '/faq'];

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return Auth::user()?->hasPermission('site_settings.manage') ?? false;
    }

    public function mount(): void
    {
        $settings = SiteSetting::query()->firstOrCreate([]);

        $this->form->fill([
            ...$settings->toArray(),
            'promo_text' => $settings->promoTextOrDefault(),
            'nav_links' => $settings->nav_links ?: SiteSetting::defaultNavLinks(),
            'footer_about' => $settings->footer_about ?: SiteSetting::defaultFooterAbout(),
            'footer_columns' => $settings->footer_columns ?: SiteSetting::defaultFooterColumns(),
            'footer_copyright_text' => $settings->footer_copyright_text ?: SiteSetting::defaultCopyrightText(),
        ]);
    }

    /**
     * The fields of one menu item: its type, the page, category or URL that goes with the type, and a label
     * (optional for pages and categories, which then use their own name).
     *
     * @return array<int, Component>
     */
    private function menuItemFields(bool $withChildren): array
    {
        $fields = [
            Select::make('type')
                ->label('Type')
                ->options(MenuLinks::TYPES)
                ->default('custom')
                ->required()
                ->native(false)
                ->live(),
            Select::make('page_id')
                ->label('Page')
                ->options(fn (): array => CmsPage::query()->orderBy('title')->pluck('title', 'id')->all())
                ->searchable()
                ->required(fn (Get $get): bool => $get('type') === 'page')
                ->visible(fn (Get $get): bool => $get('type') === 'page'),
            Select::make('category_id')
                ->label('Category')
                ->options(fn (): array => Category::query()->orderBy('name')->pluck('name', 'id')->all())
                ->searchable()
                ->required(fn (Get $get): bool => $get('type') === 'category')
                ->visible(fn (Get $get): bool => $get('type') === 'category'),
            TextInput::make('url')
                ->label('URL')
                ->maxLength(255)
                ->datalist(self::LINK_SUGGESTIONS)
                ->required(fn (Get $get): bool => ($get('type') ?? 'custom') === 'custom')
                ->visible(fn (Get $get): bool => ($get('type') ?? 'custom') === 'custom')
                ->helperText('A relative path like /shop, or a full https:// URL. Pick /corporate-gifting for the corporate quotation page.'),
            TextInput::make('label')
                ->label('Label')
                ->maxLength(60)
                ->datalist(['Ask for a corporate quotation'])
                ->required(fn (Get $get): bool => ($get('type') ?? 'custom') === 'custom')
                ->helperText(fn (Get $get): ?string => in_array($get('type'), ['page', 'category', 'blog'], true)
                    ? 'Leave blank to use the name of the page or category.'
                    : null),
        ];

        if ($withChildren) {
            $fields[] = Repeater::make('children')
                ->label('Sub-items (shown as a dropdown)')
                ->schema($this->menuItemFields(withChildren: false))
                ->columns(2)
                ->maxItems(12)
                ->defaultItems(0)
                ->reorderable()
                ->reorderableWithButtons()
                ->collapsed()
                ->itemLabel(fn (array $state): ?string => $this->menuItemLabel($state))
                ->addActionLabel('Add sub-item')
                ->columnSpanFull();
        }

        return $fields;
    }

    /**
     * What a collapsed menu item is called in the admin: its label, or the name of the page or category it points at.
     *
     * @param  array<string, mixed>  $state
     */
    private function menuItemLabel(array $state): ?string
    {
        if (filled($state['label'] ?? null)) {
            return $state['label'];
        }

        return match ($state['type'] ?? 'custom') {
            'page' => ($title = CmsPage::query()->whereKey($state['page_id'] ?? 0)->value('title')) ? $title : '(page no longer exists)',
            'category' => ($name = Category::query()->whereKey($state['category_id'] ?? 0)->value('name')) ? $name : '(category no longer exists)',
            'blog' => 'Blog',
            default => null,
        };
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Brand')
                    ->schema([
                        TextInput::make('site_name')
                            ->label('Site Name')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('tagline')
                            ->label('Tagline')
                            ->helperText('Shown under the logo on desktop, in the footer and in the default social-sharing data. The brand kit line is "Gifted, beautifully." -- keep the comma and full stop.')
                            ->maxLength(120),
                        FileUpload::make('logo_navy')
                            ->label('Logo, navy (default, on ivory)')
                            ->helperText('The master file, navy on a transparent background. Used in the header. At least 120 px wide; do not redraw or recolour it.')
                            ->image()
                            ->disk('public')
                            ->directory('site'),
                        FileUpload::make('logo_ivory')
                            ->label('Logo, ivory (for dark areas)')
                            ->helperText('The ivory master, used on deep ink areas such as the footer.')
                            ->image()
                            ->disk('public')
                            ->directory('site'),
                        FileUpload::make('monogram')
                            ->label('Monogram')
                            ->helperText('The square monogram: browser tab icon and small avatars.')
                            ->image()
                            ->disk('public')
                            ->directory('site'),
                        FileUpload::make('logo_url')
                            ->label('Previous logo (fallback)')
                            ->helperText('Only used where a brand kit logo above has not been uploaded.')
                            ->image()
                            ->disk('public')
                            ->directory('site'),
                        TextInput::make('contact_phone')
                            ->label('Contact Phone')
                            ->tel()
                            ->maxLength(30),
                        TextInput::make('contact_email')
                            ->label('Contact Email')
                            ->email()
                            ->maxLength(255),
                        TextInput::make('address')
                            ->label('Address')
                            ->maxLength(255),
                        Textarea::make('brand_description')
                            ->live(onBlur: true)
                            ->label('Brand description')
                            ->rows(4)
                            ->maxLength(1000)
                            ->helperText('One master paragraph about the business. It is reused for the Organization structured data, llms.txt, the default meta description and (once you add one) an About page -- so write it as plain, factual copy you are happy to see quoted. If left blank, those places fall back to the Footer "About" text.')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Section::make('Delivery & returns')
                    ->description('These numbers drive the delivery fee charged at checkout and every place the storefront quotes delivery or returns, including the product structured data. They start out equal to the previous fixed rules (free over ৳2,000, ৳80 inside Dhaka, ৳130 outside, 1-3 / 3-5 days, 7-day returns). The Shipping and Returns pages are separate text you edit yourself.')
                    ->schema([
                        TextInput::make('free_delivery_threshold')
                            ->label('Free delivery inside Dhaka over (৳)')
                            ->helperText('Orders with a subtotal above this ship free inside Dhaka. Set 0 to charge the Dhaka fee on every order.')
                            ->numeric()->integer()->minValue(0)->required(),
                        TextInput::make('delivery_fee_dhaka')
                            ->label('Delivery fee inside Dhaka (৳)')
                            ->numeric()->integer()->minValue(0)->required(),
                        TextInput::make('delivery_fee_outside_dhaka')
                            ->label('Delivery fee outside Dhaka (৳)')
                            ->numeric()->integer()->minValue(0)->required(),
                        TextInput::make('return_window_days')
                            ->label('Return window (days)')
                            ->helperText('Set 0 if returns are not accepted.')
                            ->numeric()->integer()->minValue(0)->maxValue(365)->required(),
                        TextInput::make('delivery_days_dhaka_min')
                            ->label('Delivery time inside Dhaka: from (days)')
                            ->numeric()->integer()->minValue(0)->maxValue(60)->required(),
                        TextInput::make('delivery_days_dhaka_max')
                            ->label('...to (days)')
                            ->numeric()->integer()->minValue(0)->maxValue(60)->required()
                            ->gte('delivery_days_dhaka_min'),
                        TextInput::make('delivery_days_outside_min')
                            ->label('Delivery time outside Dhaka: from (days)')
                            ->numeric()->integer()->minValue(0)->maxValue(60)->required(),
                        TextInput::make('delivery_days_outside_max')
                            ->label('...to (days)')
                            ->numeric()->integer()->minValue(0)->maxValue(60)->required()
                            ->gte('delivery_days_outside_min'),
                    ])
                    ->columns(2),

                Section::make('Homepage wording')
                    ->description('The text on the homepage hero tiles and in the newsletter sign-up (banner and popup). Every field is optional: left blank, the storefront shows neutral wording with no discount, percentage or ranking claim. Write a real offer here only when you are running one.')
                    ->schema([
                        TextInput::make('hero_badge')
                            ->live(onBlur: true)
                            ->label('Hero badge')
                            ->helperText('The small pill above the headline. Blank hides it.')
                            ->maxLength(80),
                        TextInput::make('hero_title')
                            ->live(onBlur: true)
                            ->label('Hero headline')
                            ->placeholder('Handcrafted tea sets & gifts, done right.')
                            ->maxLength(160),
                        Textarea::make('hero_text')
                            ->live(onBlur: true)
                            ->label('Hero paragraph')
                            ->rows(3)
                            ->maxLength(400)
                            ->columnSpanFull(),
                        TextInput::make('hero_hot_deals_text')
                            ->live(onBlur: true)
                            ->label('Special prices tile line')
                            ->placeholder('Special prices, while stock lasts')
                            ->maxLength(120),
                        TextInput::make('hero_new_arrivals_text')
                            ->live(onBlur: true)
                            ->label('New arrivals tile line')
                            ->placeholder('New gifts to explore')
                            ->maxLength(120),
                        TextInput::make('newsletter_headline')
                            ->live(onBlur: true)
                            ->label('Newsletter headline')
                            ->placeholder('New pieces, sent with care.')
                            ->helperText('Used by the homepage newsletter section and the newsletter popup. Signing up does not create a discount code, so do not promise one unless you send it yourself.')
                            ->maxLength(120)
                            ->columnSpanFull(),
                        TextInput::make('newsletter_text')
                            ->live(onBlur: true)
                            ->label('Newsletter text')
                            ->placeholder('Join our list for new arrivals and special prices.')
                            ->maxLength(240)
                            ->columnSpanFull(),
                        BrandVoiceNote::forFields(self::VOICE_FIELDS),
                    ])
                    ->columns(2),

                Section::make('Corporate gifting')
                    ->description('The /corporate-gifting page and the enquiry form on it.')
                    ->schema([
                        Textarea::make('corporate_intro')
                            ->label('Short intro on the page (optional)')
                            ->helperText('Two or three plain sentences under the page heading. Leave blank to keep the built-in sentence. State only what you can do: no discounts or minimum quantities unless you set them.')
                            ->rows(3)
                            ->maxLength(500)
                            ->live(onBlur: true)
                            ->columnSpanFull(),
                        BrandVoiceNote::under('corporate_intro'),
                        TextInput::make('corporate_notify_email')
                            ->label('Send new enquiries to')
                            ->email()
                            ->maxLength(255)
                            ->helperText('An email is sent here when someone submits the form. Leave blank to send none: enquiries are always saved under Corporate enquiries either way.')
                            ->columnSpanFull(),
                    ]),

                Section::make('Product pages')
                    ->schema([
                        TextInput::make('low_stock_threshold')
                            ->label('Say "Only a few left" at or below this stock')
                            ->helperText('A product with stock above zero and at or below this number shows "Only a few left in this colour." Set 0 to never show it.')
                            ->numeric()->integer()->minValue(0)->required(),
                    ]),

                Section::make('Header')
                    ->description('The top promo bar and main navigation shown on every page.')
                    ->schema([
                        TextInput::make('promo_text')
                            ->live(onBlur: true)
                            ->label('Top Promo Bar Text')
                            ->maxLength(255)
                            ->columnSpanFull(),
                        Toggle::make('show_categories_menu')
                            ->label('Show a Categories menu in the header')
                            ->helperText('Lists the categories marked "Show in the storefront Categories menu" (Categories > edit). Needs at least one such category to appear.')
                            ->columnSpanFull(),
                        Toggle::make('nav_auto_categories')
                            ->label('Auto-list all categories under Shop')
                            ->helperText('Adds every category marked "Show in the storefront Categories menu" as a sub-item of the Shop item, so you do not have to add them one by one. It replaces the separate Categories dropdown above. Needs an item that points at /shop.')
                            ->columnSpanFull(),
                        Repeater::make('nav_links')
                            ->label('Main Navigation Links')
                            ->helperText('Each item is a page, a category, the blog or a custom URL. Pages and categories follow their current address, so renaming a slug never breaks the menu. Add sub-items to give an item a dropdown (an accordion on phones).')
                            ->schema($this->menuItemFields(withChildren: true))
                            ->columns(2)
                            ->reorderable()
                            ->reorderableWithButtons()
                            ->collapsed()
                            ->itemLabel(fn (array $state): ?string => $this->menuItemLabel($state))
                            ->addActionLabel('Add nav link')
                            ->columnSpanFull(),
                    ]),

                Section::make('Footer')
                    ->schema([
                        Textarea::make('footer_about')
                            ->live(onBlur: true)
                            ->label('About Blurb')
                            ->rows(3)
                            ->maxLength(500)
                            ->columnSpanFull(),
                        Repeater::make('footer_columns')
                            ->label('Link columns')
                            ->helperText('Up to three columns, each with a title and links. A page or category link follows its current address.')
                            ->schema([
                                TextInput::make('title')
                                    ->label('Column title')
                                    ->required()
                                    ->maxLength(60),
                                Repeater::make('items')
                                    ->label('Links')
                                    ->schema($this->menuItemFields(withChildren: false))
                                    ->columns(2)
                                    ->reorderable()
                                    ->reorderableWithButtons()
                                    ->collapsed()
                                    ->itemLabel(fn (array $state): ?string => $this->menuItemLabel($state))
                                    ->addActionLabel('Add link')
                                    ->columnSpanFull(),
                            ])
                            ->maxItems(3)
                            ->reorderable()
                            ->reorderableWithButtons()
                            ->collapsed()
                            ->itemLabel(fn (array $state): ?string => $state['title'] ?? null)
                            ->addActionLabel('Add column')
                            ->columnSpanFull(),
                        Repeater::make('social_links')
                            ->label('Social Media Links')
                            ->schema([
                                Select::make('platform')
                                    ->options([
                                        'facebook' => 'Facebook',
                                        'instagram' => 'Instagram',
                                        'youtube' => 'YouTube',
                                        'tiktok' => 'TikTok',
                                        'twitter' => 'Twitter / X',
                                        'linkedin' => 'LinkedIn',
                                        'pinterest' => 'Pinterest',
                                        'whatsapp' => 'WhatsApp',
                                    ])
                                    ->required(),
                                TextInput::make('url')
                                    ->label('URL')
                                    ->url()
                                    ->required()
                                    ->maxLength(255),
                            ])
                            ->columns(2)
                            ->reorderable()
                            ->collapsed()
                            ->itemLabel(fn (array $state): ?string => $state['platform'] ?? null)
                            ->addActionLabel('Add social link')
                            ->columnSpanFull(),
                        TextInput::make('footer_copyright_text')
                            ->label('Copyright Text')
                            ->maxLength(255)
                            ->helperText(fn (Get $get): string => 'Shown after "© '.date('Y').' '.($get('site_name') ?: 'Site Name').'." -- the year always reflects today, so don\'t include it here.')
                            ->columnSpanFull(),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        $settings = SiteSetting::query()->firstOrCreate([]);

        // The form pre-fills a blank promo text with the generated default. Saving that
        // back as a literal would freeze today's threshold into it, so an untouched
        // default is stored as blank and keeps following the free-delivery setting.
        if (($data['promo_text'] ?? null) === SiteSetting::defaultPromoText($settings)) {
            $data['promo_text'] = null;
        }

        $settings->update($data);

        $summary = BrandVoice::summary(array_map(
            fn (string $field): string => (string) ($settings->{$field} ?? ''),
            array_keys(self::VOICE_FIELDS),
        ));

        Notification::make()
            ->title('Site settings saved')
            ->body($summary)
            ->success()
            ->send();
    }
}
