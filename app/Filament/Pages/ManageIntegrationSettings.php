<?php

namespace App\Filament\Pages;

use App\Models\IntegrationSetting;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

class ManageIntegrationSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-key';

    protected static ?string $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Integrations';

    protected static ?string $title = 'Integration Settings';

    protected static string $view = 'filament.pages.manage-integration-settings';

    protected static ?string $slug = 'integration-settings';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return Auth::user()?->hasPermission('integration_settings.manage') ?? false;
    }

    public function mount(): void
    {
        $this->form->fill(IntegrationSetting::query()->firstOrCreate([])->toArray());
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('SMTP')
                    ->description('Overrides the server\'s .env mail settings -- takes effect immediately on save, no restart needed. Leave any field blank to keep using .env for it.')
                    ->schema([
                        Select::make('mailer')
                            ->label('Mailer')
                            ->options(['smtp' => 'SMTP', 'log' => 'Log (dev only -- writes emails to the log file instead of sending)'])
                            ->native(false)
                            ->placeholder('Use .env (MAIL_MAILER)'),
                        TextInput::make('smtp_host')
                            ->label('Host')
                            ->maxLength(255),
                        TextInput::make('smtp_port')
                            ->label('Port')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(65535),
                        TextInput::make('smtp_username')
                            ->label('Username')
                            ->maxLength(255)
                            ->autocomplete('off'),
                        TextInput::make('smtp_password')
                            ->label('Password')
                            ->password()
                            ->revealable()
                            ->autocomplete('off')
                            ->maxLength(255),
                        TextInput::make('smtp_from_address')
                            ->label('From address')
                            ->email()
                            ->maxLength(255),
                        TextInput::make('smtp_from_name')
                            ->label('From name')
                            ->helperText('Defaults to the site name (Site Settings) if left blank.')
                            ->maxLength(255),
                    ])
                    ->columns(2),

                Section::make('Google OAuth')
                    ->description('Used for "Sign in with Google" on the storefront.')
                    ->schema([
                        TextInput::make('google_client_id')
                            ->label('Client ID')
                            ->maxLength(255)
                            ->autocomplete('off'),
                        TextInput::make('google_client_secret')
                            ->label('Client secret')
                            ->password()
                            ->revealable()
                            ->autocomplete('off')
                            ->maxLength(255),
                    ])
                    ->columns(2),

                Section::make('Facebook OAuth')
                    ->description('Used for "Sign in with Facebook" on the storefront.')
                    ->schema([
                        TextInput::make('facebook_app_id')
                            ->label('App ID')
                            ->maxLength(255)
                            ->autocomplete('off'),
                        TextInput::make('facebook_app_secret')
                            ->label('App secret')
                            ->password()
                            ->revealable()
                            ->autocomplete('off')
                            ->maxLength(255),
                    ])
                    ->columns(2),
            ])
            ->statePath('data');
    }

    /**
     * Fields whose form input never redisplays the stored value (Filament's
     * ->password() blanks it out on every load, for good reason). Without
     * this, saving the form for any unrelated reason -- without retyping a
     * secret that was already set -- would silently wipe it back to blank.
     */
    private const SECRET_FIELDS = ['smtp_password', 'google_client_secret', 'facebook_app_secret'];

    public function save(): void
    {
        $data = $this->form->getState();

        foreach (self::SECRET_FIELDS as $field) {
            if (blank($data[$field] ?? null)) {
                unset($data[$field]);
            }
        }

        IntegrationSetting::query()->firstOrCreate([])->update($data);

        Notification::make()
            ->title('Integration settings saved')
            ->success()
            ->send();
    }
}
