<?php

namespace App\Filament\Pages;

use App\Models\ReviewRequestSetting;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

class ManageReviewRequests extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-star';

    protected static ?string $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Review requests';

    protected static ?string $title = 'Review requests';

    protected static string $view = 'filament.pages.manage-marketing-settings';

    protected static ?string $slug = 'review-requests';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return Auth::user()?->hasPermission('review_requests.manage') ?? false;
    }

    public function mount(): void
    {
        $this->form->fill(ReviewRequestSetting::query()->firstOrCreate([])->attributesToArray() + ['whatsapp_message' => ReviewRequestSetting::DEFAULT_WHATSAPP_MESSAGE]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('When to ask')
                    ->description('A few days after an order is delivered, the customer gets one email with a review link for each piece. The link proves the purchase, works once and ends after 60 days. It runs from the scheduler, so it needs the one cron line on the server. Only orders delivered after you switch this on are asked.')
                    ->schema([
                        Toggle::make('enabled')
                            ->label('Ask customers for reviews')
                            ->helperText('Off until you switch it on.')
                            ->live(),
                        TextInput::make('days_after_delivery')
                            ->label('Days after delivery')
                            ->numeric()->integer()->minValue(0)->maxValue(60)->required(),
                        Toggle::make('email_enabled')
                            ->label('Send the request by email')
                            ->helperText('Wording: Settings > Email templates > Review request.'),
                        Toggle::make('whatsapp_enabled')
                            ->label('Offer "Send WhatsApp review request" on orders')
                            ->helperText('There is no WhatsApp sending here: the button opens WhatsApp with the message ready, and you press send. The click is recorded.'),
                    ])
                    ->columns(2),

                Section::make('One reminder')
                    ->schema([
                        Toggle::make('reminder_enabled')
                            ->label('Send one reminder if there is no review yet')
                            ->helperText('Wording: Settings > Email templates > Review request reminder. Never more than one.'),
                        TextInput::make('reminder_days')
                            ->label('Days after the first request')
                            ->numeric()->integer()->minValue(1)->maxValue(45)->required(),
                    ])
                    ->columns(2),

                Section::make('WhatsApp message')
                    ->description('What opens in WhatsApp. {name}, {order} and {link} are filled in for each order.')
                    ->schema([
                        Textarea::make('whatsapp_message')
                            ->label('Message')
                            ->rows(3)
                            ->maxLength(600)
                            ->columnSpanFull(),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $settings = ReviewRequestSetting::query()->firstOrCreate([]);

        // Only orders delivered after the requests were switched on are asked, so the moment is kept.
        if (($data['enabled'] ?? false) && ! $settings->enabled) {
            $data['enabled_at'] = now();
        }

        $message = trim((string) ($data['whatsapp_message'] ?? ''));
        $data['whatsapp_message'] = $message === '' || $message === ReviewRequestSetting::DEFAULT_WHATSAPP_MESSAGE ? null : $message;

        $settings->update($data);

        Notification::make()->title('Review request settings saved')->success()->send();
    }
}
