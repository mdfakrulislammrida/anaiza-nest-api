<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AuthorizesResourceAccess;
use App\Filament\Resources\AbandonedCheckoutResource\Pages;
use App\Filament\Widgets\AbandonedCheckoutStats;
use App\Models\AbandonedCheckout;
use App\Models\CartRecoverySetting;
use App\Models\EmailOptOut;
use App\Models\Product;
use App\Support\Coupons\CouponService;
use App\Support\Dhaka;
use App\Support\Email\TemplateDefinitions;
use App\Support\Recovery\RecoveryEmails;
use Filament\Forms;
use Filament\Infolists;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Carts and checkouts left by visitors we can reach. Visitors with no email or phone are not here (they are only counted).
 * Nothing is created or edited by hand: the shop records them, and the actions here send a reminder, open WhatsApp, make a
 * coupon for the customer, or set a row aside.
 */
class AbandonedCheckoutResource extends Resource
{
    use AuthorizesResourceAccess;

    protected static ?string $model = AbandonedCheckout::class;

    protected static ?string $navigationIcon = 'heroicon-o-shopping-bag';

    protected static ?string $navigationGroup = 'Sales';

    protected static ?string $navigationLabel = 'Abandoned checkouts';

    protected static ?string $modelLabel = 'abandoned checkout';

    protected static string $permissionKey = 'abandoned_checkouts.manage';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function form(Forms\Form $form): Forms\Form
    {
        return $form->schema([]);
    }

    /** Messages that have gone out, as "Step 1 · 14 Oct, 1:05 pm". */
    private static function messageLines(AbandonedCheckout $checkout): array
    {
        $lines = [];

        foreach ([1, 2] as $step) {
            if (isset(($checkout->messages ?? [])["step{$step}"])) {
                $lines[] = "Step {$step} · ".Dhaka::readable($checkout->messages["step{$step}"]);
            }
        }

        foreach (($checkout->messages['manual'] ?? []) as $at) {
            $lines[] = 'Sent by hand · '.Dhaka::readable($at);
        }

        return $lines;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('last_activity_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('stage')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => AbandonedCheckout::STAGES[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        'ordered' => 'success',
                        'contact_filled' => 'warning',
                        default => 'gray',
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('name')
                    ->label('Contact')
                    ->placeholder('(no name)')
                    ->description(fn (AbandonedCheckout $record): string => implode(' · ', array_filter([$record->email, $record->phone])))
                    ->searchable(['name', 'email', 'phone']),
                Tables\Columns\TextColumn::make('cart_total')
                    ->label('Cart')
                    ->formatStateUsing(fn (int $state): string => '৳'.number_format($state))
                    ->description(fn (AbandonedCheckout $record): string => count($record->items ?? []).' '.(count($record->items ?? []) === 1 ? 'piece' : 'pieces'))
                    ->sortable(),
                Tables\Columns\TextColumn::make('last_activity_at')
                    ->label('Last activity (Dhaka)')
                    ->formatStateUsing(fn ($state) => Dhaka::readable($state))
                    ->sortable(),
                Tables\Columns\TextColumn::make('messages_count')
                    ->label('Messages')
                    ->formatStateUsing(fn (int $state, AbandonedCheckout $record): string => $state === 0 && empty($record->messages['manual']) ? 'None yet' : $state.($state === 1 ? ' sent' : ' sent'))
                    ->description(fn (AbandonedCheckout $record): ?string => ($lines = self::messageLines($record)) ? implode("\n", $lines) : null),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => AbandonedCheckout::STATUSES[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        'open' => 'warning',
                        'recovered' => 'success',
                        'ordered' => 'success',
                        'unsubscribed' => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),
                Tables\Columns\IconColumn::make('whatsapp_optin')
                    ->label('WhatsApp OK')
                    ->boolean()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('coupon.code')
                    ->label('Coupon')
                    ->placeholder('—')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('utm_source')
                    ->label('Source')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(AbandonedCheckout::STATUSES),
                Tables\Filters\SelectFilter::make('stage')->options(AbandonedCheckout::STAGES),
                Tables\Filters\TernaryFilter::make('whatsapp_optin')->label('Said yes to WhatsApp'),
                Tables\Filters\Filter::make('has_email')
                    ->label('Has an email')
                    ->toggle()
                    ->query(fn (Builder $query) => $query->whereNotNull('email')),
                Tables\Filters\Filter::make('last_activity')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('Last activity from'),
                        Forms\Components\DatePicker::make('until')->label('Until'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $d) => $q->where('last_activity_at', '>=', Carbon::parse($d, Dhaka::ZONE)->startOfDay()->utc()))
                        ->when($data['until'] ?? null, fn (Builder $q, $d) => $q->where('last_activity_at', '<=', Carbon::parse($d, Dhaka::ZONE)->endOfDay()->utc()))),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->label('Cart')
                    ->infolist([
                        Infolists\Components\TextEntry::make('displayName')->label('Customer')->state(fn (AbandonedCheckout $r) => $r->displayName()),
                        Infolists\Components\TextEntry::make('email')->placeholder('—'),
                        Infolists\Components\TextEntry::make('phone')->placeholder('—'),
                        Infolists\Components\TextEntry::make('items')
                            ->label('In the cart')
                            ->state(fn (AbandonedCheckout $r) => collect($r->items ?? [])->map(fn ($i) => ($i['quantity'] ?? 1).' × '.($i['name'] ?? '').(($i['variant'] ?? null) ? ' ('.$i['variant'].')' : '').' — ৳'.number_format(($i['price'] ?? 0) * ($i['quantity'] ?? 1)))->all())
                            ->listWithLineBreaks()
                            ->columnSpanFull(),
                        Infolists\Components\TextEntry::make('viewed')
                            ->label('Last viewed')
                            ->state(fn (AbandonedCheckout $r) => Product::query()->whereIn('id', $r->viewed_products ?? [])->pluck('name')->all())
                            ->listWithLineBreaks()
                            ->placeholder('—')
                            ->columnSpanFull(),
                        Infolists\Components\TextEntry::make('recovery_link')
                            ->label('Recovery link')
                            ->state(fn (AbandonedCheckout $r) => RecoveryEmails::recoveryLink($r))
                            ->copyable()
                            ->columnSpanFull(),
                        Infolists\Components\TextEntry::make('messages_log')
                            ->label('Messages')
                            ->state(fn (AbandonedCheckout $r) => self::messageLines($r) ?: ['None yet'])
                            ->listWithLineBreaks(),
                    ]),
                Tables\Actions\Action::make('sendEmail')
                    ->label('Send email now')
                    ->icon('heroicon-o-envelope')
                    ->requiresConfirmation()
                    ->modalDescription('Sends the cart email to this customer right now, outside the quiet hours and the daily cap. It is the offer email when a coupon has been made for them, otherwise the plain reminder.')
                    ->visible(fn (AbandonedCheckout $r): bool => filled($r->email) && ! in_array($r->status, ['ordered', 'recovered', 'unsubscribed', 'ignored'], true) && $r->order_id === null)
                    ->action(function (AbandonedCheckout $record): void {
                        if (EmailOptOut::covers($record->email, $record->phone)) {
                            Notification::make()->warning()->title('This customer has unsubscribed')->send();

                            return;
                        }

                        $coupon = $record->coupon;
                        $usable = $coupon && $coupon->is_active && ! ($coupon->single_use && $coupon->used_count > 0) && ($coupon->valid_until === null || $coupon->valid_until->isFuture());

                        try {
                            RecoveryEmails::sendCart($record, $usable ? TemplateDefinitions::CART_OFFER : TemplateDefinitions::CART_REMINDER, $usable ? $coupon : null);
                        } catch (\Throwable $e) {
                            Notification::make()->danger()->title('The email could not be sent')->body($e->getMessage())->send();

                            return;
                        }

                        $messages = $record->messages ?? [];
                        $messages['manual'][] = now()->toIso8601String();
                        $record->forceFill(['messages' => $messages, 'last_message_at' => now()])->save();

                        Notification::make()->success()->title('Email sent to '.$record->email)->send();
                    }),
                Tables\Actions\Action::make('whatsapp')
                    ->label('WhatsApp')
                    ->icon('heroicon-o-chat-bubble-left-right')
                    ->color('success')
                    ->url(fn (AbandonedCheckout $r): string => route('checkouts.whatsapp', $r))
                    ->openUrlInNewTab()
                    ->visible(fn (AbandonedCheckout $r): bool => $r->whatsapp_optin && filled($r->phone) && $r->order_id === null && $r->status !== 'ignored')
                    ->tooltip('Opens WhatsApp with the message and the recovery link ready. Offered only to customers who ticked the WhatsApp box.'),
                Tables\Actions\Action::make('makeCoupon')
                    ->label('Create coupon')
                    ->icon('heroicon-o-ticket')
                    ->modalDescription('A code made for this customer only: single use, bound to their email and phone, never combined with another code.')
                    ->fillForm(function (): array {
                        $settings = CartRecoverySetting::current();

                        return [
                            'type' => $settings->coupon_type,
                            'amount' => $settings->coupon_amount,
                            'min_order' => $settings->coupon_min_order,
                            'valid_hours' => $settings->coupon_valid_hours,
                        ];
                    })
                    ->form([
                        Forms\Components\Select::make('type')->options(['percent' => 'Percent (%)', 'fixed' => 'Fixed amount (৳)'])->required(),
                        Forms\Components\TextInput::make('amount')->numeric()->integer()->minValue(1)->required(),
                        Forms\Components\TextInput::make('min_order')->label('Minimum order (৳, optional)')->numeric()->integer()->minValue(0),
                        Forms\Components\TextInput::make('valid_hours')->label('Valid for (hours, from now)')->numeric()->integer()->minValue(1)->maxValue(720)->required(),
                    ])
                    ->visible(fn (AbandonedCheckout $r): bool => $r->order_id === null && $r->status !== 'ignored' && $r->coupon_id === null)
                    ->action(function (AbandonedCheckout $record, array $data): void {
                        $coupon = CouponService::createFor($record->email, $record->phone, $data['type'], (int) $data['amount'], $data['min_order'] ? (int) $data['min_order'] : null, (int) $data['valid_hours'], $record->id);
                        $record->forceFill(['coupon_id' => $coupon->id])->save();

                        Notification::make()->success()->title('Coupon '.$coupon->code.' made')
                            ->body($coupon->valueLabel().' off, until '.Dhaka::readable($coupon->valid_until).' (Dhaka time). "Send email now" sends it.')
                            ->send();
                    }),
                Tables\Actions\Action::make('ignore')
                    ->label('Mark ignored')
                    ->icon('heroicon-o-eye-slash')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalDescription('Stops every reminder for this checkout. It stays in the list.')
                    ->visible(fn (AbandonedCheckout $r): bool => $r->status === AbandonedCheckout::STATUS_OPEN)
                    ->action(fn (AbandonedCheckout $record) => $record->forceFill(['status' => AbandonedCheckout::STATUS_IGNORED])->save()),
            ])
            ->bulkActions([])
            ->poll('60s');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAbandonedCheckouts::route('/'),
        ];
    }

    public static function getWidgets(): array
    {
        return [AbandonedCheckoutStats::class];
    }
}
