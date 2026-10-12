<?php

namespace App\Filament\Pages;

use App\Models\IntegrationSetting;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
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
                    ->description('Used for "Continue with Google" on the storefront. Off until you switch it on, and it cannot be switched on without a client ID and secret.')
                    ->schema([
                        Toggle::make('google_login_enabled')
                            ->label('Enable Google login')
                            ->helperText('Off: the Google button is hidden on the shop and its sign-in address answers with a friendly message. Customers who signed up with Google keep their accounts.')
                            ->live()
                            ->afterStateUpdated(fn ($state, Set $set, Get $get) => self::guardSwitch('google', $state, $set, $get))
                            ->columnSpanFull(),
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
                    ->description('Used for "Continue with Facebook" on the storefront. Off until you switch it on, and it cannot be switched on without an app ID and secret.')
                    ->schema([
                        Toggle::make('facebook_login_enabled')
                            ->label('Enable Facebook login')
                            ->helperText('Off: the Facebook button is hidden on the shop and its sign-in address answers with a friendly message. Customers who signed up with Facebook keep their accounts.')
                            ->live()
                            ->afterStateUpdated(fn ($state, Set $set, Get $get) => self::guardSwitch('facebook', $state, $set, $get))
                            ->columnSpanFull(),
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

    /**
     * Whether the provider has an ID and a secret to use, counting what is in the form, what is already saved (a secret is
     * never shown again, so a blank box means "keep it") and what the server's .env supplies.
     *
     * @param  array<string, mixed>  $state
     */
    private static function credentialsReady(string $provider, array $state): bool
    {
        $fields = IntegrationSetting::SOCIAL_PROVIDERS[$provider];
        $saved = IntegrationSetting::query()->first();

        $candidate = new IntegrationSetting([
            $fields['id'] => filled($state[$fields['id']] ?? null) ? $state[$fields['id']] : $saved?->{$fields['id']},
            $fields['secret'] => filled($state[$fields['secret']] ?? null) ? $state[$fields['secret']] : $saved?->{$fields['secret']},
        ]);

        return IntegrationSetting::hasCredentials($provider, $candidate);
    }

    private static function missingMessage(string $provider): string
    {
        $fields = IntegrationSetting::SOCIAL_PROVIDERS[$provider];
        $label = $fields['label'];
        $idName = $provider === 'google' ? 'client ID' : 'app ID';

        return "{$label} login stays off: add the {$label} {$idName} and secret first, then switch it on.";
    }

    /** The switch cannot be turned on without credentials: it springs back off, with the reason. */
    private static function guardSwitch(string $provider, mixed $state, Set $set, Get $get): void
    {
        if (! $state) {
            return;
        }

        $fields = IntegrationSetting::SOCIAL_PROVIDERS[$provider];

        if (self::credentialsReady($provider, [
            $fields['id'] => $get($fields['id']),
            $fields['secret'] => $get($fields['secret']),
        ])) {
            return;
        }

        $set($fields['switch'], false);

        Notification::make()->warning()->title(self::missingMessage($provider))->send();
    }

    public function save(): void
    {
        $data = $this->form->getState();

        // A switch that is on without credentials is never saved as on, whatever the form said.
        foreach (IntegrationSetting::SOCIAL_PROVIDERS as $provider => $fields) {
            if (($data[$fields['switch']] ?? false) && ! self::credentialsReady($provider, $data)) {
                $data[$fields['switch']] = false;
                $this->data[$fields['switch']] = false;

                Notification::make()->danger()->title(self::missingMessage($provider))->send();
            }
        }

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
