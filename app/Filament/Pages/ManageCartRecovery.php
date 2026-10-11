<?php

namespace App\Filament\Pages;

use App\Models\CartRecoverySetting;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

class ManageCartRecovery extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-shopping-cart';

    protected static ?string $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Cart recovery';

    protected static ?string $title = 'Cart recovery';

    protected static string $view = 'filament.pages.manage-marketing-settings';

    protected static ?string $slug = 'cart-recovery';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return Auth::user()?->hasPermission('cart_recovery.manage') ?? false;
    }

    public function mount(): void
    {
        $settings = CartRecoverySetting::query()->firstOrCreate([]);

        $this->form->fill($settings->attributesToArray() + [
            'whatsapp_message' => $settings->whatsappMessage(),
            'step1_delay_hours' => round($settings->step1_delay_minutes / 60, 2),
            'step2_delay_hours' => round($settings->step2_delay_minutes / 60, 2),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Reminders')
                    ->description('When a visitor leaves a cart and we have their email, they get up to two reminders. Each goes at most once, never during the quiet hours (it waits), and never after they order or unsubscribe. It runs from the scheduler, so it needs the one cron line on the server.')
                    ->schema([
                        Toggle::make('enabled')
                            ->label('Send cart reminders')
                            ->helperText('Off until you switch it on. Only carts left after you switch it on are written to.')
                            ->columnSpanFull(),
                        Toggle::make('step1_enabled')->label('Step 1: a plain reminder'),
                        TextInput::make('step1_delay_hours')
                            ->label('Step 1 goes after (hours without activity)')
                            ->numeric()->minValue(0.25)->maxValue(168)->required(),
                        Toggle::make('step2_enabled')->label('Step 2: a second email'),
                        TextInput::make('step2_delay_hours')
                            ->label('Step 2 goes after (hours without activity)')
                            ->numeric()->minValue(0.5)->maxValue(168)->required(),
                        Toggle::make('step2_coupon_enabled')
                            ->label('Step 2 carries a coupon')
                            ->helperText('One plain offer with its real end time, a code made for that customer, single use. Wording: Email templates > Cart offer. Off: step 2 is a second plain reminder.')
                            ->live()
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Section::make('The coupon')
                    ->description('A new code is made for each customer, like ANZ-7K3M9. It works once, only for their email or phone, is never combined with another code, and ends at the exact time shown in the email (Dhaka time).')
                    ->schema([
                        Select::make('coupon_type')
                            ->label('Type')
                            ->options(['percent' => 'Percent (%)', 'fixed' => 'Fixed amount (৳)'])
                            ->required()
                            ->live(),
                        TextInput::make('coupon_amount')
                            ->label(fn ($get) => $get('coupon_type') === 'fixed' ? 'Amount (৳)' : 'Percent (%)')
                            ->numeric()->integer()->minValue(1)->maxValue(fn ($get) => $get('coupon_type') === 'fixed' ? 100000 : 100)->required(),
                        TextInput::make('coupon_min_order')
                            ->label('Minimum order (৳, optional)')
                            ->numeric()->integer()->minValue(0),
                        TextInput::make('coupon_valid_hours')
                            ->label('Valid for (hours)')
                            ->numeric()->integer()->minValue(1)->maxValue(720)->required(),
                    ])
                    ->columns(2),

                Section::make('Limits')
                    ->schema([
                        TextInput::make('max_messages')
                            ->label('Most messages per checkout')
                            ->numeric()->integer()->minValue(1)->maxValue(2)->required(),
                        TextInput::make('daily_cap')
                            ->label('Most emails per day')
                            ->helperText('Counted by the Dhaka calendar day. What is left waits until the next day.')
                            ->numeric()->integer()->minValue(1)->maxValue(5000)->required(),
                        TextInput::make('quiet_start')
                            ->label('Quiet hours begin (Dhaka time)')
                            ->type('time')->required(),
                        TextInput::make('quiet_end')
                            ->label('Quiet hours end (Dhaka time)')
                            ->type('time')->required(),
                        TextInput::make('min_cart_value')
                            ->label('Smallest cart worth a reminder (৳)')
                            ->numeric()->integer()->minValue(0)->required(),
                        TextInput::make('max_age_days')
                            ->label('Stop reminding after (days)')
                            ->helperText('A reminder weeks later is unwelcome, so a cart older than this is left.')
                            ->numeric()->integer()->minValue(1)->maxValue(60)->required(),
                        TextInput::make('retention_days')
                            ->label('Delete records after (days)')
                            ->numeric()->integer()->minValue(7)->maxValue(365)->required(),
                    ])
                    ->columns(2),

                Section::make('WhatsApp message')
                    ->description('What opens in WhatsApp from Abandoned checkouts. {name} and {link} are filled in. Offered only for customers who ticked the WhatsApp box.')
                    ->schema([
                        Textarea::make('whatsapp_message')->label('Message')->rows(3)->maxLength(600)->columnSpanFull(),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $settings = CartRecoverySetting::query()->firstOrCreate([]);

        if (($data['enabled'] ?? false) && ! $settings->enabled) {
            $data['enabled_at'] = now();
        }

        $data['step1_delay_minutes'] = (int) round(((float) ($data['step1_delay_hours'] ?? 1)) * 60);
        $data['step2_delay_minutes'] = (int) round(((float) ($data['step2_delay_hours'] ?? 48)) * 60);
        unset($data['step1_delay_hours'], $data['step2_delay_hours']);

        $message = trim((string) ($data['whatsapp_message'] ?? ''));
        $data['whatsapp_message'] = $message === '' || $message === CartRecoverySetting::DEFAULT_WHATSAPP_MESSAGE ? null : $message;

        $settings->update($data);

        Notification::make()->title('Cart recovery settings saved')->success()->send();
    }
}
