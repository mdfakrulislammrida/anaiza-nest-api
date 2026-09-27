<?php

namespace App\Filament\Pages;

use App\Models\EmailTemplate;
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

class ManageOrderConfirmationEmail extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-envelope';

    protected static ?string $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Order Confirmation Email';

    protected static string $view = 'filament.pages.manage-order-confirmation-email';

    protected static ?string $slug = 'order-confirmation-email';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return Auth::user()?->hasPermission('email_templates.manage') ?? false;
    }

    public function mount(): void
    {
        $template = EmailTemplate::query()->firstOrCreate(
            ['key' => EmailTemplate::ORDER_CONFIRMATION],
            [
                'subject' => EmailTemplate::defaultOrderConfirmationSubject(),
                'body_html' => EmailTemplate::defaultOrderConfirmationBodyHtml(),
                'is_active' => false,
            ]
        );

        $this->form->fill($template->toArray());
    }

    public function form(Form $form): Form
    {
        $tokenList = '{{order_number}}, {{customer_name}}, {{site_name}}, {{order_total}}, {{order_date}}, {{payment_method}}, {{delivery_address}}';

        return $form
            ->schema([
                Section::make('Order Confirmation Email')
                    ->description(
                        'Customize the wording customers see when their order is confirmed. This only replaces the subject and the greeting/intro text -- '.
                        'the invoice PDF, order summary table, and branded header/footer stay exactly as designed no matter what you put here.'
                    )
                    ->schema([
                        Toggle::make('is_active')
                            ->label('Use custom wording')
                            ->helperText('Off: sends the default confirmation email below. On: uses your subject and body instead.')
                            ->columnSpanFull(),
                        TextInput::make('subject')
                            ->label('Subject')
                            ->required()
                            ->maxLength(255)
                            ->helperText("Available placeholders: {$tokenList}")
                            ->columnSpanFull(),
                        Textarea::make('body_html')
                            ->label('Body (HTML)')
                            ->required()
                            ->rows(12)
                            ->helperText(
                                "Raw HTML, inserted as-is into the email's intro section -- not sanitized, so only paste content you trust. ".
                                "Available placeholders: {$tokenList}"
                            )
                            ->columnSpanFull(),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        EmailTemplate::query()
            ->where('key', EmailTemplate::ORDER_CONFIRMATION)
            ->update($data);

        Notification::make()
            ->title('Order confirmation email saved')
            ->success()
            ->send();
    }
}
