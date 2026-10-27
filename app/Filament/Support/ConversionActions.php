<?php

namespace App\Filament\Support;

use App\Filament\Resources\OrderResource;
use App\Models\Order;
use App\Support\ConversionAttempts;
use App\Support\ConversionEvents;
use App\Support\WalletPayments;
use Filament\Notifications\Notification;
use Illuminate\Support\HtmlString;

/**
 * What the order page says about the Purchase the shop's own server sends to Meta and TikTok, and the "Send Purchase again" button.
 *
 * @template TAction of \Filament\Actions\Action|\Filament\Tables\Actions\Action
 */
final class ConversionActions
{
    private const COLORS = ['sent' => '#15803d', 'retrying' => '#b45309', 'failed' => '#b91c1c', 'skipped' => '#6b7280', 'none' => '#6b7280'];

    /** One plain line per platform: "Meta Purchase: sent / failed (reason) / retrying". */
    public static function summary(?Order $order): HtmlString
    {
        if ($order === null) {
            return new HtmlString('');
        }

        $html = '';

        foreach (ConversionAttempts::PLATFORMS as $platform => $name) {
            $state = ConversionAttempts::state($order, $platform);

            // TikTok is mentioned only when something was tried for it: most shops do not use it.
            if ($state['state'] === 'none' && $platform !== 'meta') {
                continue;
            }

            $label = $state['state'] === 'none' ? "{$name} Purchase: ".self::whyNotSent($order) : $state['label'];
            $html .= '<p style="color:'.self::COLORS[$state['state']].';font-weight:600">'.e($label).'</p>';
        }

        return new HtmlString($html);
    }

    /** Open the section when a sale did not get through. */
    public static function needsAttention(?Order $order): bool
    {
        if ($order === null) {
            return false;
        }

        foreach (array_keys(ConversionAttempts::PLATFORMS) as $platform) {
            if (in_array(ConversionAttempts::state($order, $platform)['state'], ['failed', 'retrying'], true)) {
                return true;
            }
        }

        return false;
    }

    private static function whyNotSent(Order $order): string
    {
        return match (true) {
            $order->isLegacy() => 'not sent (an order brought over from the old site is never reported)',
            ! ConversionEvents::allowed((bool) $order->marketing_consent) => 'not sent (the visitor did not allow marketing cookies)',
            WalletPayments::isWallet($order->payment_method) && $order->payment_status !== 'verified' => 'not sent yet (it is sent when the payment is marked verified)',
            default => 'no record (placed before the shop kept a record, or made in the admin)',
        };
    }

    /**
     * @param  class-string<TAction>  $actionClass
     * @return TAction
     */
    public static function resend(string $actionClass)
    {
        return $actionClass::make('resendPurchase')
            ->label('Send Purchase again')
            ->icon('heroicon-o-arrow-path')
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading('Send the Purchase again?')
            ->modalDescription('Only a platform that did not get the sale is tried again. If an earlier try did reach it, Meta merges the two because the event id is the same.')
            ->modalSubmitActionLabel('Send again')
            ->visible(fn (Order $record): bool => OrderResource::can('update', $record) && ConversionAttempts::canResend($record))
            ->action(function (Order $record): void {
                $result = ConversionAttempts::resend($record);

                Notification::make()
                    ->title($result['ok'] ? 'Purchase tried again' : 'Not sent')
                    ->body($result['message'])
                    ->{$result['ok'] ? 'success' : 'warning'}()
                    ->send();
            });
    }
}
