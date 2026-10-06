<?php

namespace App\Filament\Pages;

use App\Filament\Support\BrandVoiceNote;
use App\Models\CookieConsentSetting;
use App\Models\Page;
use App\Support\BrandVoice;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page as FilamentPage;
use Illuminate\Support\Facades\Auth;

class ManageCookieConsent extends FilamentPage implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Cookie consent';

    protected static ?string $title = 'Cookie consent';

    protected static string $view = 'filament.pages.manage-cookie-consent';

    protected static ?string $slug = 'cookie-consent';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return Auth::user()?->hasPermission('cookie_consent.manage') ?? false;
    }

    public function mount(): void
    {
        $settings = CookieConsentSetting::query()->firstOrCreate([]);

        // Blank fields show the built-in wording, so there is always something to edit from.
        $this->form->fill([
            ...$settings->toArray(),
            'banner_text' => $settings->bannerText(),
            'privacy_link_label' => $settings->label('privacy'),
            'accept_label' => $settings->label('accept'),
            'reject_label' => $settings->label('reject'),
            'customize_label' => $settings->label('customize'),
            'save_label' => $settings->label('save'),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Banner')
                    ->description('A small notice at the bottom of the storefront, with Accept all, Reject non-essential and Customize. The choice is kept for 12 months, and a "Cookie settings" link in the footer reopens it.')
                    ->schema([
                        Toggle::make('enabled')
                            ->label('Show the cookie banner')
                            ->default(true),
                        Select::make('mode')
                            ->label('Mode')
                            ->options(CookieConsentSetting::MODES)
                            ->required()
                            ->native(false)
                            ->helperText('Notice only: tracking runs as it does today and the banner informs. Opt-in required: Google Analytics and marketing tags, the Meta and TikTok pixels, UTM storage and the server-side Meta and TikTok events all wait until the visitor allows them. The mode and this switch are built into the storefront, so changing them needs a storefront rebuild; the wording below updates on its own.')
                            ->columnSpanFull(),
                        Textarea::make('banner_text')
                            ->label('Banner text')
                            ->rows(3)
                            ->maxLength(400)
                            ->live(onBlur: true)
                            ->helperText('Keep it calm and short. Say only what is true of your shop, and have it checked if you need it to meet a particular law.')
                            ->columnSpanFull(),
                        BrandVoiceNote::under('banner_text'),
                        Select::make('privacy_page_id')
                            ->label('Privacy policy page')
                            ->options(fn (): array => Page::query()->orderBy('title')->pluck('title', 'id')->all())
                            ->searchable()
                            ->helperText('Linked from the banner. Leave blank to show no link.'),
                        TextInput::make('privacy_link_label')
                            ->label('Link label')
                            ->maxLength(60),
                    ])
                    ->columns(2),
                Section::make('Button labels')
                    ->schema([
                        TextInput::make('accept_label')->label('Accept all')->maxLength(40),
                        TextInput::make('reject_label')->label('Reject non-essential')->maxLength(40),
                        TextInput::make('customize_label')->label('Customize')->maxLength(40),
                        TextInput::make('save_label')->label('Save choices')->maxLength(40),
                    ])
                    ->columns(2),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        // Wording left as the built-in text is stored blank, so it keeps following the default.
        $defaults = CookieConsentSetting::defaultLabels();
        foreach (['accept', 'reject', 'customize', 'save'] as $key) {
            if (trim((string) ($data["{$key}_label"] ?? '')) === $defaults[$key]) {
                $data["{$key}_label"] = null;
            }
        }
        if (trim((string) ($data['privacy_link_label'] ?? '')) === $defaults['privacy']) {
            $data['privacy_link_label'] = null;
        }
        if (trim((string) ($data['banner_text'] ?? '')) === CookieConsentSetting::defaultBannerText()) {
            $data['banner_text'] = null;
        }

        $settings = CookieConsentSetting::query()->firstOrCreate([]);
        $settings->update($data);

        Notification::make()
            ->title('Cookie consent saved')
            ->body(BrandVoice::summary([(string) $settings->banner_text]))
            ->success()
            ->send();
    }
}
