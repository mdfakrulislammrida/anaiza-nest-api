<?php

namespace App\Filament\Pages;

use App\Models\SiteSetting;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
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
            'promo_text' => $settings->promo_text ?: SiteSetting::defaultPromoText(),
            'nav_links' => $settings->nav_links ?: SiteSetting::defaultNavLinks(),
            'footer_about' => $settings->footer_about ?: SiteSetting::defaultFooterAbout(),
            'footer_links' => $settings->footer_links ?: SiteSetting::defaultFooterLinks(),
            'footer_copyright_text' => $settings->footer_copyright_text ?: SiteSetting::defaultCopyrightText(),
        ]);
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
                        FileUpload::make('logo_url')
                            ->label('Logo')
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
                    ])
                    ->columns(2),

                Section::make('Header')
                    ->description('The top promo bar and main navigation shown on every page.')
                    ->schema([
                        TextInput::make('promo_text')
                            ->label('Top Promo Bar Text')
                            ->maxLength(255)
                            ->columnSpanFull(),
                        Repeater::make('nav_links')
                            ->label('Main Navigation Links')
                            ->schema([
                                TextInput::make('label')
                                    ->required()
                                    ->maxLength(60),
                                TextInput::make('url')
                                    ->label('URL')
                                    ->required()
                                    ->maxLength(255)
                                    ->helperText('A relative path like /shop, or a full https:// URL.'),
                            ])
                            ->columns(2)
                            ->reorderable()
                            ->reorderableWithButtons()
                            ->collapsed()
                            ->itemLabel(fn (array $state): ?string => $state['label'] ?? null)
                            ->addActionLabel('Add nav link')
                            ->columnSpanFull(),
                    ]),

                Section::make('Footer')
                    ->schema([
                        Textarea::make('footer_about')
                            ->label('About Blurb')
                            ->rows(3)
                            ->maxLength(500)
                            ->columnSpanFull(),
                        Repeater::make('footer_links')
                            ->label('Customer Care Links')
                            ->schema([
                                TextInput::make('label')
                                    ->required()
                                    ->maxLength(60),
                                TextInput::make('url')
                                    ->label('URL')
                                    ->required()
                                    ->maxLength(255)
                                    ->helperText('A relative path like /faq, or a full https:// URL.'),
                            ])
                            ->columns(2)
                            ->reorderable()
                            ->reorderableWithButtons()
                            ->collapsed()
                            ->itemLabel(fn (array $state): ?string => $state['label'] ?? null)
                            ->addActionLabel('Add link')
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

        SiteSetting::query()->firstOrCreate([])->update($data);

        Notification::make()
            ->title('Site settings saved')
            ->success()
            ->send();
    }
}
