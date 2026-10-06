<?php

namespace App\Filament\Pages;

use App\Filament\Support\BrandVoiceNote;
use App\Models\PaymentSetting;
use App\Support\BrandVoice;
use App\Support\WalletPayments;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

class ManagePaymentSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Payment Settings';

    protected static ?string $title = 'Payment Settings';

    protected static string $view = 'filament.pages.manage-payment-settings';

    protected static ?string $slug = 'payment-settings';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return Auth::user()?->hasPermission('payment_settings.manage') ?? false;
    }

    public function mount(): void
    {
        $settings = PaymentSetting::query()->firstOrCreate([]);

        // A blank field shows the built-in steps, so there is always something to edit from.
        $this->form->fill([
            ...$settings->toArray(),
            ...collect(WalletPayments::METHODS)
                ->mapWithKeys(fn (string $method): array => ["{$method}_instructions" => $settings->instructionsFor($method)])
                ->all(),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                ...collect(WalletPayments::METHODS)->map(fn (string $method) => $this->walletSection($method))->all(),
                Section::make('Cash on delivery')
                    ->schema([
                        Toggle::make('cod_enabled')
                            ->label('Cash on Delivery Enabled')
                            ->default(true),
                    ]),
                Section::make('New wallet orders')
                    ->schema([
                        TextInput::make('payment_notify_email')
                            ->label('Email me when a wallet order arrives')
                            ->email()
                            ->maxLength(255)
                            ->helperText('The email carries the order number, amount, sender number and transaction ID. Leave blank to use the contact email in Site Settings.'),
                    ]),
            ])
            ->statePath('data');
    }

    private function walletSection(string $method): Section
    {
        $label = WalletPayments::LABELS[$method];

        return Section::make($label)
            ->description("Customers who choose {$label} are shown this number, the exact amount to send, the steps below, and asked for the number they paid from and the transaction ID. A method with no number is not offered at checkout.")
            ->collapsible()
            ->schema([
                TextInput::make("{$method}_number")
                    ->label("{$label} number")
                    ->tel()
                    ->maxLength(20),
                FileUpload::make("{$method}_logo")
                    ->label('Logo (optional)')
                    ->helperText('Shown beside the name at checkout. PNG, JPG or WebP, under 1 MB. Without one, the name is shown as plain text. Only upload a logo you have the right to use.')
                    ->image()
                    ->disk('public')
                    ->directory('payment-logos')
                    ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/webp'])
                    ->maxSize(1024),
                RichEditor::make("{$method}_instructions")
                    ->label('Steps for the customer')
                    ->toolbarButtons(['bold', 'italic', 'orderedList', 'bulletList', 'undo', 'redo'])
                    ->live(onBlur: true)
                    ->helperText('Use {{amount}} and {{number}} where the exact amount and your number should appear. Keep it short and plain: one action per step.')
                    ->columnSpanFull(),
                BrandVoiceNote::under("{$method}_instructions"),
            ])
            ->columns(2);
    }

    public function save(): void
    {
        $data = $this->form->getState();

        // Steps left as the built-in wording (or emptied) are stored blank, so they keep following the default.
        foreach (WalletPayments::METHODS as $method) {
            $steps = (string) ($data["{$method}_instructions"] ?? '');

            if (trim(strip_tags($steps)) === '' || trim($steps) === WalletPayments::defaultInstructions($method)) {
                $data["{$method}_instructions"] = null;
            }
        }

        $settings = PaymentSetting::query()->firstOrCreate([]);
        $settings->update($data);

        $summary = BrandVoice::summary(array_map(
            fn (string $method): string => (string) $settings->{"{$method}_instructions"},
            WalletPayments::METHODS,
        ));

        Notification::make()
            ->title('Payment settings saved')
            ->body($summary)
            ->success()
            ->send();
    }
}
