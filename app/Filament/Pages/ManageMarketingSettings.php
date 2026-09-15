<?php

namespace App\Filament\Pages;

use App\Models\MarketingSetting;
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
