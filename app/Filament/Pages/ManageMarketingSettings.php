<?php

namespace App\Filament\Pages;

use App\Models\MarketingSetting;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

class ManageMarketingSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-megaphone';

    protected static ?string $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Marketing Settings';

    protected static ?string $title = 'Marketing Settings';

    protected static string $view = 'filament.pages.manage-marketing-settings';

    protected static ?string $slug = 'marketing-settings';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return Auth::user()?->hasPermission('marketing_settings.manage') ?? false;
    }

    public function mount(): void
    {
        $this->form->fill(MarketingSetting::query()->firstOrCreate([])->toArray());
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Client-Side Tracking')
                    ->description('Pixel/tag IDs injected into the storefront -- these are not secret, they end up in the page source either way.')
                    ->schema([
                        TextInput::make('gtm_container_id')
                            ->label('Google Tag Manager Container ID')
                            ->helperText("e.g. 'GTM-XXXXXXX'")
                            ->maxLength(50),
                        TextInput::make('ga4_id')
                            ->label('Google Analytics 4 ID')
                            ->helperText("e.g. 'G-XXXXXXXXXX'")
                            ->maxLength(50),
                        TextInput::make('meta_pixel_id')
                            ->label('Meta Pixel ID')
                            ->maxLength(50),
                        TextInput::make('tiktok_pixel_id')
                            ->label('TikTok Pixel ID')
                            ->maxLength(50),
                    ])
                    ->columns(2),

                Section::make('Server-Side Conversion Tracking')
                    ->description('Used to send a Purchase event directly from our server to Meta/TikTok when an order is placed, deduplicated against the client-side pixel event above via a shared event ID. These are secrets -- never exposed to the storefront.')
                    ->schema([
                        TextInput::make('meta_capi_access_token')
                            ->label('Meta Conversions API Access Token')
                            ->password()
                            ->revealable()
                            ->autocomplete('off')
                            ->helperText('Requires the Meta Pixel ID above to also be set. From Events Manager -> Settings -> Conversions API.')
                            ->maxLength(2000),
                        TextInput::make('tiktok_events_api_access_token')
                            ->label('TikTok Events API Access Token')
                            ->password()
                            ->revealable()
                            ->autocomplete('off')
                            ->helperText('Requires the TikTok Pixel ID above to also be set. From TikTok Events Manager -> your pixel -> Generate Access Token.')
                            ->maxLength(2000),
                    ])
                    ->columns(2),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        MarketingSetting::query()->firstOrCreate([])->update($data);

        Notification::make()
            ->title('Marketing settings saved')
            ->success()
            ->send();
    }
}
