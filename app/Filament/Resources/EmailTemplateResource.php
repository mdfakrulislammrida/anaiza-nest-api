<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AuthorizesResourceAccess;
use App\Filament\Resources\EmailTemplateResource\Pages;
use App\Models\EmailTemplate;
use App\Models\SiteSetting;
use App\Support\Email\EmailBuilder;
use App\Support\Email\SampleData;
use App\Support\Email\TemplateDefinitions;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

class EmailTemplateResource extends Resource
{
    use AuthorizesResourceAccess;

    protected static ?string $model = EmailTemplate::class;

    protected static ?string $navigationIcon = 'heroicon-o-envelope';

    protected static ?string $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Email templates';

    protected static ?string $modelLabel = 'email template';

    protected static ?string $pluralModelLabel = 'email templates';

    protected static string $permissionKey = 'email_templates.manage';

    /**
     * The placeholders, as helper text for the wording fields.
     */
    private static function placeholderHelp(): HtmlString
    {
        $items = collect(TemplateDefinitions::TOKENS)
            ->map(fn (string $meaning, string $token): string => '<code>'.e($token).'</code> '.e($meaning))
            ->implode('<br>');

        return new HtmlString('Placeholders you can use here and in the subject:<br>'.$items.'<br>A placeholder that does not apply is simply left out.');
    }

    /**
     * The wording as it is now in the form (not as last saved), for the preview and the test email.
     *
     * @return array<string, mixed>
     */
    public static function wordingFrom(array $state): array
    {
        return [
            'subject' => $state['subject'] ?? null,
            'intro_html' => $state['intro_html'] ?? null,
            'closing_html' => $state['closing_html'] ?? null,
            'footer_note' => $state['footer_note'] ?? null,
            'use_custom_html' => (bool) ($state['use_custom_html'] ?? false),
            'custom_html' => $state['custom_html'] ?? null,
        ];
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Wording')
                    ->description(fn (?EmailTemplate $record): string => $record
                        ? $record->label().'. The branded header and footer, the main button and, on the confirmation, the invoice PDF stay as designed whatever you write here.'
                        : '')
                    ->schema([
                        Forms\Components\Toggle::make('is_active')
                            ->label('Use my wording')
                            ->helperText('Off: this email is sent with the built-in wording. On: your wording below is used, and any field you leave blank falls back to the built-in text.')
                            ->columnSpanFull(),
                        Forms\Components\TextInput::make('subject')
                            ->label('Subject')
                            ->maxLength(255)
                            ->live(onBlur: true)
                            ->helperText(self::placeholderHelp())
                            ->columnSpanFull(),
                        Forms\Components\Textarea::make('intro_html')
                            ->label('Greeting and intro (HTML)')
                            ->rows(6)
                            ->live(onBlur: true)
                            ->helperText('Shown first. Raw HTML, put into the email as written, so only paste what you trust. Blank: the built-in text.')
                            ->columnSpanFull(),
                        Forms\Components\Textarea::make('closing_html')
                            ->label('Closing (HTML)')
                            ->rows(3)
                            ->live(onBlur: true)
                            ->helperText('Shown after the main button, in the serif type. Blank: the built-in closing. To send none, enter an HTML comment such as <!-- none -->.')
                            ->columnSpanFull(),
                        Forms\Components\Textarea::make('footer_note')
                            ->label('Footer note (optional)')
                            ->rows(2)
                            ->live(onBlur: true)
                            ->columnSpanFull(),
                        Forms\Components\Toggle::make('use_custom_html')
                            ->label('Custom full HTML')
                            ->helperText('Replaces everything between the branded header and footer (intro, tables, button and closing) with your own HTML. Use the block placeholders where you want the real tables. A missing placeholder is fine.')
                            ->live()
                            ->columnSpanFull(),
                        Forms\Components\Textarea::make('custom_html')
                            ->label('Custom HTML')
                            ->rows(14)
                            ->live(onBlur: true)
                            ->visible(fn (Get $get): bool => (bool) $get('use_custom_html'))
                            ->helperText(self::placeholderHelp())
                            ->columnSpanFull(),
                    ]),
                Forms\Components\Section::make('Preview')
                    ->description('Filled in with a made-up order. It follows what you type once you leave a field. The invoice PDF is not shown here.')
                    ->schema([
                        Forms\Components\Placeholder::make('preview')
                            ->hiddenLabel()
                            ->content(function (Get $get, ?EmailTemplate $record): HtmlString {
                                if (! $record) {
                                    return new HtmlString('');
                                }

                                $built = EmailBuilder::build(
                                    $record->key,
                                    SampleData::order(),
                                    SampleData::customer(),
                                    SiteSetting::query()->first() ?? new SiteSetting,
                                    self::wordingFrom([
                                        'subject' => $get('subject'),
                                        'intro_html' => $get('intro_html'),
                                        'closing_html' => $get('closing_html'),
                                        'footer_note' => $get('footer_note'),
                                        'use_custom_html' => $get('use_custom_html'),
                                        'custom_html' => $get('custom_html'),
                                    ]),
                                );

                                return new HtmlString(
                                    '<p style="margin-bottom:.5rem"><strong>Subject:</strong> '.e($built['subject']).'</p>'
                                    .'<iframe title="Email preview" sandbox="" srcdoc="'.e($built['html']).'" style="width:100%;height:760px;border:1px solid #ddd;border-radius:4px;background:#fff"></iframe>'
                                );
                            })
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('key')
                    ->label('Email')
                    ->formatStateUsing(fn (string $state): string => TemplateDefinitions::label($state))
                    ->description(fn (EmailTemplate $record): string => TemplateDefinitions::all()[$record->key]['description'] ?? ''),
                Tables\Columns\TextColumn::make('is_active')
                    ->label('Wording')
                    ->badge()
                    ->state(fn (EmailTemplate $record): string => ! $record->is_active ? 'Built-in' : ($record->use_custom_html ? 'Custom HTML' : 'Your wording'))
                    ->color(fn (string $state): string => $state === 'Built-in' ? 'gray' : 'success'),
                Tables\Columns\TextColumn::make('subject')
                    ->label('Subject')
                    ->state(fn (EmailTemplate $record): string => EmailBuilder::parts($record->key)['subject'])
                    ->limit(60),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Last edited')
                    ->dateTime()
                    ->since(),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('Edit'),
            ])
            ->paginated(false);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEmailTemplates::route('/'),
            'edit' => Pages\EditEmailTemplate::route('/{record}/edit'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
