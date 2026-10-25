<?php

namespace App\Filament\Pages;

use App\Models\IntegrationSetting;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\View;
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
                            ->autocomplete('new-password')
                            ->placeholder(fn (): string => $this->savedPlaceholder('smtp_password'))
                            ->helperText(fn (): string => $this->savedHelp('smtp_password'))
                            ->suffixAction($this->clearAction('smtp_password', 'clearSmtpPassword', 'SMTP password'))
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
                        // The address Google sends people back to. Worked out by the shop, never typed: copy it into Google Cloud.
                        View::make('filament.forms.callback-url')
                            ->viewData(['provider' => 'Google', 'url' => IntegrationSetting::callbackUrl('google'), 'where' => 'Google Cloud > Credentials > Authorised redirect URIs', 'problem' => self::callbackProblem()])
                            ->columnSpanFull(),
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
                            ->autocomplete('new-password')
                            ->placeholder(fn (): string => $this->savedPlaceholder('google_client_secret'))
                            ->helperText(fn (): string => $this->savedHelp('google_client_secret'))
                            ->suffixAction($this->clearAction('google_client_secret', 'clearGoogleSecret', 'Google client secret'))
                            ->maxLength(255),
                    ])
                    ->columns(2),

                Section::make('Facebook OAuth')
                    ->description('Used for "Continue with Facebook" on the storefront. Off until you switch it on, and it cannot be switched on without an app ID and secret.')
                    ->schema([
                        View::make('filament.forms.callback-url')
                            ->viewData(['provider' => 'Facebook', 'url' => IntegrationSetting::callbackUrl('facebook'), 'where' => 'Meta for Developers > Facebook Login > Settings > Valid OAuth Redirect URIs', 'problem' => self::callbackProblem()])
                            ->columnSpanFull(),
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
                            ->autocomplete('new-password')
                            ->placeholder(fn (): string => $this->savedPlaceholder('facebook_app_secret'))
                            ->helperText(fn (): string => $this->savedHelp('facebook_app_secret'))
                            ->suffixAction($this->clearAction('facebook_app_secret', 'clearFacebookSecret', 'Facebook app secret'))
                            ->maxLength(255),
                    ])
                    ->columns(2),
            ])
            ->statePath('data');
    }

    /**
     * What is wrong with the address the shop sends providers back to, if anything: it is worked out from APP_URL, which has to be the
     * API's own public https address. Null when it looks right.
     */
    private static function callbackProblem(): ?string
    {
        $base = (string) config('app.url');
        $host = (string) parse_url($base, PHP_URL_HOST);

        if ($host === '' || in_array($host, ['localhost', '127.0.0.1'], true) || str_ends_with($host, '.test')) {
            return 'APP_URL on this server is "'.$base.'", which is not the public address of the API. Set APP_URL in .env to the API address (for example https://api.anaizanest.com), or the address below will not match what Google sends.';
        }

        if (! str_starts_with($base, 'https://') && app()->isProduction()) {
            return 'APP_URL on this server is not https. Google only accepts https addresses (localhost apart): set APP_URL in .env to the https address of the API.';
        }

        return null;
    }

    /** "Saved (ends in ••••1234)" or "Not saved yet": says a secret exists without ever showing it. */
    public function savedPlaceholder(string $field): string
    {
        $hint = IntegrationSetting::query()->first()?->secretHint($field);

        return $hint === null ? 'Not saved yet' : "Saved ({$hint})";
    }

    public function savedHelp(string $field): string
    {
        return IntegrationSetting::query()->first()?->secretHint($field) !== null
            ? 'A secret is saved. Leave this blank to keep it, or paste a new one to replace it.'
            : 'Nothing saved yet.';
    }

    private function clearAction(string $field, string $name, string $label): Action
    {
        return Action::make($name)
            ->icon('heroicon-m-trash')
            ->color('danger')
            ->tooltip('Clear the saved secret')
            ->visible(fn (): bool => IntegrationSetting::query()->first()?->secretHint($field) !== null)
            ->requiresConfirmation()
            ->modalHeading("Clear the saved {$label}?")
            ->modalDescription('Whatever it is used for stops working until a new one is saved (Google and Facebook login are also off while they have no secret).')
            ->modalSubmitActionLabel('Clear secret')
            ->action(function () use ($field, $label): void {
                IntegrationSetting::query()->firstOrCreate([])->forceFill([$field => null])->save();
                $this->data[$field] = null;

                Notification::make()->title("{$label} cleared")->success()->send();
            });
    }

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

        // A blank secret keeps what is saved (the form is never filled with a secret, so blank is the normal case).
        foreach (IntegrationSetting::SECRET_FIELDS as $field) {
            if (blank($data[$field] ?? null)) {
                unset($data[$field]);
            } else {
                $data[$field] = trim($data[$field]);
            }
        }

        IntegrationSetting::query()->firstOrCreate([])->update($data);

        // Nothing just typed stays on the page.
        foreach (IntegrationSetting::SECRET_FIELDS as $field) {
            $this->data[$field] = null;
        }

        Notification::make()
            ->title('Integration settings saved')
            ->success()
            ->send();
    }
}
