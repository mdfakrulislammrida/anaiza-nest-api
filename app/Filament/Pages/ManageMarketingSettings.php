<?php

namespace App\Filament\Pages;

use App\Models\MarketingSetting;
use App\Support\MetaTestEvent;
use Filament\Actions\Action as PageAction;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

class ManageMarketingSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-megaphone';

    protected static ?string $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Marketing Settings';

    protected static ?string $title = 'Marketing Settings';

    protected static string $view = 'filament.pages.manage-marketing-settings';

    protected static ?string $slug = 'marketing-settings';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return Auth::user()?->hasPermission('marketing_settings.manage') ?? false;
    }

    public function mount(): void
    {
        $this->form->fill(MarketingSetting::query()->firstOrCreate([])->toArray());
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Client-Side Tracking')
                    ->description('Pixel/tag IDs injected into the storefront -- these are not secret, they end up in the page source either way.')
                    ->schema([
                        TextInput::make('gtm_container_id')
                            ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? trim($state) : null)
                            ->label('Google Tag Manager Container ID')
                            ->helperText("e.g. 'GTM-XXXXXXX'")
                            ->maxLength(50),
                        TextInput::make('ga4_id')
                            ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? trim($state) : null)
                            ->label('Google Analytics 4 ID')
                            ->helperText("e.g. 'G-XXXXXXXXXX'")
                            ->maxLength(50),
                        TextInput::make('meta_pixel_id')
                            ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? trim($state) : null)
                            ->label('Meta Pixel ID')
                            ->maxLength(50),
                        TextInput::make('tiktok_pixel_id')
                            ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? trim($state) : null)
                            ->label('TikTok Pixel ID')
                            ->maxLength(50),
                    ])
                    ->columns(2),

                Section::make('Server-Side Conversion Tracking')
                    ->description('Used to send a Purchase event directly from our server to Meta/TikTok when an order is placed, deduplicated against the client-side pixel event above via a shared event ID. These are secrets: stored encrypted, never shown again once saved and never sent to the storefront. Leave a token field blank to keep the saved one; type a new one to replace it.')
                    ->schema([
                        Toggle::make('meta_capi_enabled')
                            ->label('Send Purchase to Meta from Laravel (CAPI)')
                            ->default(true)
                            ->helperText('Keep this ON. bKash, Nagad and Rocket orders only get a Purchase from Laravel (when payment is verified), because the browser does not send one for them. For other orders Meta deduplicates the Laravel and the Stape Purchase because both use the same event id (order-<number>). Turn it OFF only if you accept losing Purchase for wallet orders.'),
                        Toggle::make('tiktok_events_enabled')
                            ->label('Send Purchase to TikTok from Laravel (Events API)')
                            ->default(true)
                            ->helperText('Nothing is sent to TikTok without a TikTok Pixel ID and an access token anyway. Turn it OFF if you do not use TikTok.'),
                        TextInput::make('meta_capi_access_token')
                            ->label('Meta Conversions API Access Token')
                            ->password()
                            ->revealable()
                            ->autocomplete('new-password')
                            ->placeholder(fn (): string => $this->savedPlaceholder('meta_capi_access_token'))
                            ->helperText(fn (): string => $this->savedHelp('meta_capi_access_token', 'Requires the Meta Pixel ID above to also be set. From Events Manager -> Settings -> Conversions API.'))
                            ->suffixAction($this->clearAction('meta_capi_access_token', 'clearMetaToken', 'Meta'))
                            ->maxLength(2000),
                        TextInput::make('tiktok_events_api_access_token')
                            ->label('TikTok Events API Access Token')
                            ->password()
                            ->revealable()
                            ->autocomplete('new-password')
                            ->placeholder(fn (): string => $this->savedPlaceholder('tiktok_events_api_access_token'))
                            ->helperText(fn (): string => $this->savedHelp('tiktok_events_api_access_token', 'Requires the TikTok Pixel ID above to also be set. From TikTok Events Manager -> your pixel -> Generate Access Token.'))
                            ->suffixAction($this->clearAction('tiktok_events_api_access_token', 'clearTikTokToken', 'TikTok'))
                            ->maxLength(2000),
                        TextInput::make('server_container_url')
                            ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? trim($state) : null)
                            ->label('Server container address (Stape)')
                            ->placeholder('https://metrics.example.com')
                            ->helperText('The address of the server-side Google Tag Manager container: https://, no slash at the end. Not a secret. The browser tags read it from the GTM constant "AN - Server URL" (leave that one as NONE until the container works); keep the two the same. Nothing on the shop reads this field yet.')
                            ->maxLength(255)
                            ->rules([fn (): \Closure => function (string $attribute, mixed $value, \Closure $fail): void {
                                $problem = MarketingSetting::serverUrlProblem(is_string($value) ? $value : null);

                                if ($problem !== null) {
                                    $fail($problem);
                                }
                            }])
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Section::make('Test event codes')
                    ->description('Optional. While one is filled in, the matching server-side Purchase event also carries it, so it appears under Test events in that platform. Remove the code once you have finished testing: events sent with a test code are not counted for real.')
                    ->schema([
                        TextInput::make('meta_test_event_code')
                            ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? trim($state) : null)
                            ->label('Meta test event code')
                            ->helperText('From Events Manager > Test events, like TEST12345. Remove it after testing.')
                            ->maxLength(60)
                            ->autocomplete('off'),
                        TextInput::make('tiktok_test_event_code')
                            ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? trim($state) : null)
                            ->label('TikTok test event code')
                            ->helperText('From Events Manager > Test events, like TEST12345. Remove it after testing.')
                            ->maxLength(60)
                            ->autocomplete('off'),
                    ])
                    ->columns(2),
            ])
            ->statePath('data');
    }

    protected function getHeaderActions(): array
    {
        return [
            PageAction::make('sendMetaTest')
                ->label('Send Meta test event')
                ->icon('heroicon-o-paper-airplane')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Send a test event to Meta?')
                ->modalDescription('Uses the saved Pixel ID and access token (save first if you just changed them), and the test event code if one is set. Without a test code, Meta counts it as a real custom event named TestEvent.')
                ->action(function (): void {
                    $result = MetaTestEvent::send(MarketingSetting::query()->firstOrCreate([]), request()->ip(), request()->userAgent());

                    Notification::make()
                        ->title($result['ok'] ? 'Meta test event sent' : 'Meta test event failed')
                        ->body($result['message'])
                        ->color($result['ok'] ? 'success' : 'danger')
                        ->icon($result['ok'] ? 'heroicon-o-check-circle' : 'heroicon-o-x-circle')
                        ->persistent(! $result['ok'])
                        ->send();
                }),
        ];
    }

    /** "Saved (ends in ••••1234)" or "Not saved yet": says a value exists without ever showing it. */
    public function savedPlaceholder(string $field): string
    {
        $hint = MarketingSetting::query()->first()?->secretHint($field);

        return $hint === null ? 'Not saved yet' : "Saved ({$hint})";
    }

    public function savedHelp(string $field, string $about): string
    {
        $saved = MarketingSetting::query()->first()?->secretHint($field) !== null;

        return ($saved ? 'A token is saved. Leave this blank to keep it, or paste a new one to replace it. ' : 'Nothing saved yet. ').$about;
    }

    private function clearAction(string $field, string $name, string $platform): Action
    {
        return Action::make($name)
            ->icon('heroicon-m-trash')
            ->color('danger')
            ->tooltip('Clear the saved token')
            ->visible(fn (): bool => MarketingSetting::query()->first()?->secretHint($field) !== null)
            ->requiresConfirmation()
            ->modalHeading("Clear the saved {$platform} token?")
            ->modalDescription("Server-side {$platform} purchase events stop being sent until a new token is saved.")
            ->modalSubmitActionLabel('Clear token')
            ->action(function () use ($field, $platform): void {
                MarketingSetting::query()->firstOrCreate([])->forceFill([$field => null])->save();
                $this->data[$field] = null;

                Notification::make()->title("{$platform} token cleared")->success()->send();
            });
    }

    public function save(): void
    {
        $data = $this->form->getState();

        // A blank secret keeps what is saved (the form is never filled with a secret, so blank is the normal case).
        foreach (MarketingSetting::SECRET_FIELDS as $field) {
            if (blank($data[$field] ?? null)) {
                unset($data[$field]);
            } else {
                $data[$field] = trim($data[$field]);
            }
        }

        MarketingSetting::query()->firstOrCreate([])->update($data);

        // Nothing just typed stays on the page.
        foreach (MarketingSetting::SECRET_FIELDS as $field) {
            $this->data[$field] = null;
        }

        Notification::make()
            ->title('Marketing settings saved')
            ->success()
            ->send();
    }
}
