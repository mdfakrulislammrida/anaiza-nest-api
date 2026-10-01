<?php

namespace App\Filament\Pages;

use App\Models\PopupSetting;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

class ManagePopupSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-window';

    protected static ?string $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Popups';

    protected static ?string $title = 'Popup Settings';

    protected static string $view = 'filament.pages.manage-popup-settings';

    protected static ?string $slug = 'popup-settings';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return Auth::user()?->hasPermission('popup_settings.manage') ?? false;
    }

    public function mount(): void
    {
        $this->form->fill(PopupSetting::query()->firstOrCreate([])->toArray());
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Newsletter Popup')
                    ->description('A close (X) button is always shown, regardless of these settings -- it can never become unclosable.')
                    ->schema(self::popupSchema('newsletter'))
                    ->columns(2),

                Section::make('Gift Finder Popup')
                    ->description('Links through to the existing Gift Finder page -- the popup itself is just a teaser, not the full quiz.')
                    ->schema(self::popupSchema('giftfinder'))
                    ->columns(2),
            ])
            ->statePath('data');
    }

    /**
     * @return array<int, Component>
     */
    private static function popupSchema(string $prefix): array
    {
        return [
            Toggle::make("{$prefix}_enabled")
                ->label('Enabled')
                ->columnSpanFull(),
            Select::make("{$prefix}_trigger")
                ->label('Show')
                ->options(PopupSetting::TRIGGERS)
                ->native(false)
                ->live()
                ->default('delay')
                ->required(),
            TextInput::make("{$prefix}_delay_seconds")
                ->label('Delay (seconds)')
                ->numeric()
                ->minValue(0)
                ->maxValue(300)
                ->default(5)
                ->visible(fn (Get $get): bool => $get("{$prefix}_trigger") === 'delay')
                ->required(fn (Get $get): bool => $get("{$prefix}_trigger") === 'delay'),
            Select::make("{$prefix}_pages")
                ->label('Show on')
                ->options(PopupSetting::PAGES)
                ->multiple()
                ->native(false)
                ->default(['all'])
                ->helperText('Picking "All pages" shows it everywhere regardless of any other page also selected.')
                ->columnSpanFull(),
            FileUpload::make("{$prefix}_image")
                ->label('Image (optional)')
                ->image()
                ->disk('public')
                ->directory('popups')
                ->columnSpanFull(),
        ];
    }

    public function save(): void
    {
        $data = $this->form->getState();

        PopupSetting::query()->firstOrCreate([])->update($data);

        Notification::make()
            ->title('Popup settings saved')
            ->success()
            ->send();
    }
}
