<?php

namespace App\Filament\Support;

use App\Models\CourierSetting;
use App\Models\Order;
use App\Support\Courier\CourierBooking;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\BulkAction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;

/**
 * "Send to courier" on an order: one order (the order page and a row of the list) and many (the list's bulk action, at
 * most 50). The permission is checked here as well as in the booking itself, so hiding a button is never the only guard.
 *
 * @template TAction of \Filament\Actions\Action|\Filament\Tables\Actions\Action
 */
final class CourierActions
{
    public static function allowed(): bool
    {
        return Auth::user()?->hasPermission('orders.send_to_courier') ?? false;
    }

    /**
     * @param  class-string<TAction>  $actionClass
     * @return TAction
     */
    public static function send(string $actionClass)
    {
        return $actionClass::make('sendToCourier')
            ->label(fn (Order $record): string => $record->courier_last_error ? 'Try again: send to courier' : 'Send to courier')
            ->icon('heroicon-o-truck')
            ->color('info')
            ->modalHeading(fn (Order $record): string => 'Send order #'.$record->id.' to a courier?')
            ->modalDescription('This is what will be sent. The cash to collect is worked out here from the order, never typed.')
            ->modalSubmitActionLabel('Send to courier')
            ->form(fn (Order $record): array => [
                Select::make('courier')
                    ->label('Courier')
                    ->options(self::readyOptions())
                    ->default(CourierSetting::current()->defaultCourier())
                    ->required()
                    ->native(false)
                    ->live(),
                Placeholder::make('preview')
                    ->label('Sent to the courier')
                    ->content(fn (Get $get): HtmlString => self::previewHtml($record, (string) $get('courier'))),
                Toggle::make('mark_shipped')
                    ->label('Set the order to Shipped (this sends the customer the shipped email, once)')
                    ->default(true)
                    ->visible(fn () => in_array($record->status, ['pending', 'processing'], true)),
            ])
            ->visible(fn (Order $record): bool => self::allowed() && $record->courier_booked_at === null && ! in_array($record->status, ['delivered', 'cancelled'], true))
            ->action(function (Order $record, array $data): void {
                if (! self::allowed()) {
                    return;
                }

                $result = CourierBooking::book($record, (string) $data['courier'], Auth::user(), (bool) ($data['mark_shipped'] ?? false));

                $result['ok']
                    ? Notification::make()->success()->title('Sent to the courier')->body($result['message'])->send()
                    : Notification::make()->danger()->title('Not sent')->body($result['message'])->persistent()->send();
            });
    }

    /**
     * @param  class-string<BulkAction>  $actionClass
     * @return BulkAction
     */
    public static function bulk(string $actionClass)
    {
        return $actionClass::make('sendToCourierBulk')
            ->label('Send to courier')
            ->icon('heroicon-o-truck')
            ->visible(fn (): bool => self::allowed())
            ->modalHeading('Send the selected orders to a courier?')
            ->modalDescription('At most '.CourierBooking::BULK_MAX.' at a time. Each is checked on its own: an order that cannot be sent says why, and the others still go. Each one the courier accepts is real.')
            ->form([
                Select::make('courier')
                    ->label('Courier')
                    ->options(self::readyOptions())
                    ->default(fn () => CourierSetting::current()->defaultCourier())
                    ->required()
                    ->native(false),
                Toggle::make('mark_shipped')
                    ->label('Set each booked order to Shipped (sends the shipped email, once each)')
                    ->default(true),
            ])
            ->action(function (Collection $records, array $data): void {
                if (! self::allowed()) {
                    return;
                }

                $results = CourierBooking::bulk($records, (string) $data['courier'], Auth::user(), (bool) $data['mark_shipped']);
                $sent = count(array_filter($results, fn (array $line): bool => $line['ok']));

                $lines = collect($results)->map(fn (array $line): string => '#'.$line['order_id'].': '.($line['ok'] ? 'sent. ' : 'NOT sent. ').$line['message'])->implode("\n");

                Notification::make()
                    ->title("{$sent} of ".count($results).' sent')
                    ->body(new HtmlString(nl2br(e($lines))))
                    ->color($sent === count($results) ? 'success' : 'warning')
                    ->persistent()
                    ->send();
            })
            ->deselectRecordsAfterCompletion();
    }

    /** @return array<string, string> the couriers that are switched on and have their details saved */
    private static function readyOptions(): array
    {
        $settings = CourierSetting::current();

        return collect($settings->readyCouriers())->mapWithKeys(fn (string $courier): array => [$courier => CourierSetting::COURIERS[$courier]])->all();
    }

    /** The exact data, in words, with what stops it (and what to be careful of) first. */
    private static function previewHtml(Order $order, string $courier): HtmlString
    {
        if (! isset(CourierSetting::COURIERS[$courier])) {
            return new HtmlString('<p>Choose a courier to see what will be sent.</p>');
        }

        $plan = CourierBooking::preview($order, $courier);
        $html = '';

        foreach ($plan['blocks'] as $block) {
            $html .= '<p style="color:#b91c1c;font-weight:600">Cannot be sent: '.e($block).'</p>';
        }

        foreach ($plan['warnings'] as $warning) {
            $html .= '<p style="color:#b45309;font-weight:600">Take care: '.e($warning).'</p>';
        }

        if ($courier === CourierSetting::STEADFAST) {
            $html .= '<p style="color:#b45309">Steadfast has no test mode: this is a real parcel.</p>';
        } elseif (CourierSetting::current()->pathao_sandbox) {
            $html .= '<p style="color:#b45309;font-weight:600">Pathao is in SANDBOX mode: this is a test booking, not a real parcel.</p>';
        }

        $html .= '<dl style="display:grid;grid-template-columns:max-content 1fr;gap:4px 16px">';
        foreach ($plan['fields'] as $label => $value) {
            $html .= '<dt style="color:#6b7280">'.e($label).'</dt><dd>'.e((string) $value).'</dd>';
        }

        return new HtmlString($html.'</dl>');
    }
}
