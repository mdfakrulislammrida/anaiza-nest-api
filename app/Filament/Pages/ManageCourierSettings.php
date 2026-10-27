<?php

namespace App\Filament\Pages;

use App\Models\CourierSetting;
use App\Support\Courier\CourierException;
use App\Support\Courier\PathaoClient;
use App\Support\Courier\SteadfastClient;
use Filament\Actions\Action;
use Filament\Forms\Components\Actions\Action as FormAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * Settings > Courier: Steadfast and Pathao in one screen. Every secret is stored encrypted and is never shown again once
 * saved (the field says "Saved" and stays blank; leave it blank to keep it). The one exception is the webhook token, which is
 * made here to be pasted into the Steadfast panel: it is shown while it is new, and not after it has been saved.
 */
class ManageCourierSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-truck';

    protected static ?string $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Courier';

    protected static ?string $title = 'Courier';

    protected static string $view = 'filament.pages.manage-courier-settings';

    protected static ?string $slug = 'courier-settings';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return Auth::user()?->hasPermission('integration_settings.manage') ?? false;
    }

    public function mount(): void
    {
        $settings = CourierSetting::current();

        // Secrets are never put back in the form: a blank field means "keep what is saved".
        $this->form->fill(collect($settings->toArray())->except(['pathao_access_token', 'pathao_refresh_token'])->all() + array_fill_keys(CourierSetting::SECRETS, ''));
    }

    public function form(Form $form): Form
    {
        $settings = CourierSetting::current();
        $saved = fn (string $field): string => filled($settings->{$field}) ? 'Saved. Leave blank to keep it.' : 'Not saved yet';

        return $form
            ->schema([
                Section::make('Sending orders')
                    ->schema([
                        Select::make('default_courier')
                            ->label('Default courier')
                            ->options(CourierSetting::COURIERS)
                            ->native(false)
                            ->placeholder('None: ask each time')
                            ->helperText('Used when you send an order without choosing, and for automatic booking.'),
                        Toggle::make('auto_book')
                            ->label('Book automatically when an order becomes Processing')
                            ->helperText('Off by default: you book each order with a click. When on, it still never books an order that is risky (a blocked buyer), a wallet order whose payment is not verified, or one with no phone or address; those wait for you.'),
                        TextInput::make('invoice_prefix')
                            ->label('Order number prefix (optional)')
                            ->maxLength(20)
                            ->helperText('Put in front of the order number when it is sent to a courier (for example "AN-"). Use one on a test site that shares a courier account with the live shop, so a test order 12 can never block the real order 12: a courier refuses a number it already has.'),
                        TextInput::make('default_weight')
                            ->label('Default parcel weight (kg)')
                            ->numeric()->minValue(0.5)->maxValue(10)->step(0.5)->default(0.5)->required()
                            ->helperText('Sent to Pathao (it accepts 0.5 to 10).'),
                    ])
                    ->columns(2),

                Section::make('Steadfast')
                    ->description('Steadfast has no test mode: every booking is a real parcel. "Test connection" only checks the service and your balance; it never books anything.')
                    ->schema([
                        Toggle::make('steadfast_enabled')->label('Use Steadfast')->columnSpanFull(),
                        TextInput::make('steadfast_api_key')
                            ->label('API key')
                            ->password()->revealable()->autocomplete('new-password')->maxLength(300)
                            ->placeholder($saved('steadfast_api_key')),
                        TextInput::make('steadfast_secret_key')
                            ->label('Secret key')
                            ->password()->revealable()->autocomplete('new-password')->maxLength(300)
                            ->placeholder($saved('steadfast_secret_key'))
                            ->helperText('From the Steadfast panel, API section. If Steadfast refuses the keys (401) nothing more is sent until you save them again: ten refusals in five minutes lock the account for an hour.'),
                        TextInput::make('steadfast_tracking_url')
                            ->label('Tracking page link (optional)')
                            ->maxLength(300)
                            ->placeholder('https://… with {code} where the tracking code goes')
                            ->helperText('Copy it from a Steadfast tracking page you have opened. Without it the customer sees the code but no link.')
                            ->columnSpanFull(),
                        Placeholder::make('steadfast_webhook_url')
                            ->label('Webhook URL: paste into the Steadfast panel (Webhook page)')
                            ->content(fn (): HtmlString => new HtmlString('<code style="user-select:all;word-break:break-all">'.e(url('/api/webhooks/steadfast')).'</code>')),
                        TextInput::make('steadfast_webhook_token')
                            ->label('Webhook auth token')
                            ->password()->revealable()->autocomplete('new-password')->maxLength(100)
                            ->placeholder($saved('steadfast_webhook_token'))
                            ->helperText('Paste the same token into the Steadfast panel. Letters, digits and - _ . = only. Generate one, copy it now, then save: after saving it is not shown again. With no token saved, the webhook is off.')
                            ->rule('regex:/^[A-Za-z0-9._=-]+$/')
                            ->suffixAction(
                                FormAction::make('generateToken')
                                    ->label('Generate')
                                    ->tooltip('Generate a token')
                                    ->icon('heroicon-o-key')
                                    ->action(fn (Set $set) => $set('steadfast_webhook_token', Str::random(40))),
                            ),
                        Placeholder::make('steadfast_webhook_last')
                            ->label('Last webhook received')
                            ->content(fn (): string => $settings->steadfast_webhook_last_at?->timezone('Asia/Dhaka')->format('j M Y, g:i A') ?? 'None yet. Status checks run every 15 minutes either way.'),
                    ])
                    ->columns(2),

                Section::make('Pathao')
                    ->description('Pathao has a sandbox: with it on, bookings go to Pathao\'s test system and are not real parcels. The login must match: sandbox credentials for the sandbox, live credentials for live.')
                    ->schema([
                        Toggle::make('pathao_enabled')->label('Use Pathao'),
                        Toggle::make('pathao_sandbox')->label('Sandbox (test system, no real parcels)')->default(true),
                        TextInput::make('pathao_client_id')->label('Client ID')->password()->revealable()->autocomplete('new-password')->maxLength(300)->placeholder($saved('pathao_client_id')),
                        TextInput::make('pathao_client_secret')->label('Client secret')->password()->revealable()->autocomplete('new-password')->maxLength(300)->placeholder($saved('pathao_client_secret')),
                        TextInput::make('pathao_username')->label('Username (the merchant email)')->password()->revealable()->autocomplete('new-password')->maxLength(300)->placeholder($saved('pathao_username')),
                        TextInput::make('pathao_password')->label('Password')->password()->revealable()->autocomplete('new-password')->maxLength(300)->placeholder($saved('pathao_password')),
                        TextInput::make('pathao_store_id')
                            ->label('Store ID')
                            ->numeric()->maxLength(20)
                            ->helperText('Pathao\'s number for your pickup store. "Test connection" lists the stores on your account with their ids.'),
                        TextInput::make('pathao_tracking_url')
                            ->label('Tracking page link (optional)')
                            ->maxLength(300)
                            ->placeholder('https://… with {code} where the consignment id goes'),
                        TextInput::make('pathao_delivery_type')->label('Delivery type')->numeric()->default(48)->helperText('48 is normal delivery.'),
                        TextInput::make('pathao_item_type')->label('Item type')->numeric()->default(2)->helperText('2 is a parcel.'),
                    ])
                    ->columns(2),
            ])
            ->statePath('data');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('testSteadfast')
                ->label('Test Steadfast')
                ->icon('heroicon-o-signal')
                ->color('gray')
                ->action(fn () => $this->testSteadfast()),
            Action::make('testPathao')
                ->label('Test Pathao')
                ->icon('heroicon-o-signal')
                ->color('gray')
                ->action(fn () => $this->testPathao()),
        ];
    }

    /** Saved details only: save first, then test. Checks the service and the balance; never books. */
    public function testSteadfast(): void
    {
        $settings = CourierSetting::current();

        try {
            $client = SteadfastClient::forButton($settings);
            $client->ping();
            $balance = $client->balance(force: true);

            Notification::make()->success()->title('Steadfast is working')->body('The service answered and the keys were accepted. Balance: ৳'.number_format($balance, 2).'.')->send();
        } catch (CourierException $e) {
            // Not persistent: a failed test must not wait in the session to pop up on some other page.
            Notification::make()->danger()->title('Steadfast did not connect')->body($e->getMessage())->duration(15000)->send();
        }
    }

    /** Saved details only: logs in and lists the stores. */
    public function testPathao(): void
    {
        $settings = CourierSetting::current();

        if ($settings->pathao_auth_failed_at !== null) {
            // A person asked: one fresh try.
            $settings->forceFill(['pathao_auth_failed_at' => null])->save();
        }

        try {
            $stores = PathaoClient::forButton($settings)->stores();
            $list = $stores === [] ? 'No stores were listed.' : collect($stores)->map(fn (array $store): string => $store['id'].': '.$store['name'])->implode('; ');

            Notification::make()->success()
                ->title('Pathao is working ('.($settings->pathao_sandbox ? 'sandbox' : 'live').')')
                ->body('Logged in. Stores on this account: '.$list)->persistent()->send();
        } catch (CourierException $e) {
            Notification::make()->danger()->title('Pathao did not connect')->body($e->getMessage())->duration(15000)->send();
        }
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $settings = CourierSetting::current();

        // A blank secret keeps what is saved; a filled one replaces it.
        foreach (CourierSetting::SECRETS as $field) {
            if (blank($data[$field] ?? null)) {
                unset($data[$field]);
            }
        }

        $fill = collect($data)->only([
            'default_courier', 'auto_book', 'invoice_prefix', 'default_weight', 'steadfast_enabled', 'steadfast_tracking_url',
            'pathao_enabled', 'pathao_sandbox', 'pathao_store_id', 'pathao_tracking_url', 'pathao_delivery_type', 'pathao_item_type', ...CourierSetting::SECRETS,
        ])->all();

        // New keys: Steadfast gets a clean start (a refusal flag from the old keys no longer applies).
        if (isset($fill['steadfast_api_key']) || isset($fill['steadfast_secret_key'])) {
            $fill['steadfast_auth_failed_at'] = null;
        }

        // A new login, or another environment: the saved tokens belong to the old one.
        $loginChanged = collect(['pathao_client_id', 'pathao_client_secret', 'pathao_username', 'pathao_password'])->contains(fn (string $field): bool => isset($fill[$field]));
        $environmentChanged = isset($fill['pathao_sandbox']) && (bool) $fill['pathao_sandbox'] !== (bool) $settings->pathao_sandbox;

        if ($loginChanged || $environmentChanged) {
            $fill += ['pathao_access_token' => null, 'pathao_refresh_token' => null, 'pathao_token_expires_at' => null, 'pathao_auth_failed_at' => null];
        }

        $settings->forceFill($fill)->save();

        // Blank the secret fields again, so nothing just typed stays on the page (fill() leaves a key it is not given alone).
        $this->form->fill(collect($settings->fresh()->toArray())->except(['pathao_access_token', 'pathao_refresh_token'])->all() + array_fill_keys(CourierSetting::SECRETS, ''));

        Notification::make()->title('Courier settings saved')->success()->send();
    }
}
