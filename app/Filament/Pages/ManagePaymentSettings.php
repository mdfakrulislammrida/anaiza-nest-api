<?php

namespace App\Filament\Pages;

use App\Models\PaymentSetting;
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
        $this->form->fill(PaymentSetting::query()->firstOrCreate([])->toArray());
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('bkash_number')
                    ->label('bKash Number')
                    ->tel()
                    ->maxLength(20),
                TextInput::make('nagad_number')
                    ->label('Nagad Number')
                    ->tel()
                    ->maxLength(20),
                Toggle::make('cod_enabled')
                    ->label('Cash on Delivery Enabled')
                    ->default(true),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        PaymentSetting::query()->firstOrCreate([])->update($data);

        Notification::make()
            ->title('Payment settings saved')
            ->success()
            ->send();
    }
}
