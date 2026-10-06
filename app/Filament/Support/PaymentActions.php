<?php

namespace App\Filament\Support;

use App\Models\Order;
use App\Support\WalletPayments;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * The two ways a wallet payment leaves "awaiting verification". They are the only way: the payment
 * status field itself is read-only everywhere in the admin. Each records who decided and when, and
 * keeps a note. They work the same on the order page and on a row of the orders list.
 *
 * @template TAction of \Filament\Actions\Action|\Filament\Tables\Actions\Action
 */
final class PaymentActions
{
    /**
     * @param  class-string<TAction>  $actionClass
     * @return TAction
     */
    public static function verify(string $actionClass)
    {
        return $actionClass::make('markPaymentVerified')
            ->label('Mark verified')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->modalHeading('Mark this payment as verified?')
            ->modalDescription('Do this only after you have found this transaction ID in your wallet app, for the full amount.')
            ->modalSubmitActionLabel('Mark verified')
            ->form([
                Textarea::make('note')
                    ->label('Note (optional)')
                    ->rows(2)
                    ->maxLength(500),
            ])
            ->visible(fn (Order $record): bool => self::canDecide($record) && $record->payment_status !== 'verified')
            ->action(fn (Order $record, array $data) => self::decide($record, 'verified', $data['note'] ?? null));
    }

    /**
     * @param  class-string<TAction>  $actionClass
     * @return TAction
     */
    public static function fail(string $actionClass)
    {
        return $actionClass::make('markPaymentFailed')
            ->label('Mark failed')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->modalHeading('Mark this payment as failed?')
            ->modalDescription('The customer is told we could not match the payment and is asked to contact you. Say why in the note, for your own records.')
            ->modalSubmitActionLabel('Mark failed')
            ->form([
                Textarea::make('note')
                    ->label('Note')
                    ->required()
                    ->rows(2)
                    ->maxLength(500),
            ])
            ->visible(fn (Order $record): bool => self::canDecide($record) && $record->payment_status !== 'failed')
            ->action(fn (Order $record, array $data) => self::decide($record, 'failed', $data['note'] ?? null));
    }

    private static function canDecide(Order $order): bool
    {
        return WalletPayments::isWallet($order->payment_method) || $order->payment_status !== 'cod';
    }

    public static function decide(Order $order, string $status, ?string $note): void
    {
        DB::transaction(function () use ($order, $status, $note): void {
            $order->forceFill([
                'payment_status' => $status,
                'payment_verified_at' => now(),
                'payment_verified_by' => Auth::id(),
                'payment_note' => filled($note) ? trim($note) : null,
            ])->save();
        });

        Notification::make()
            ->success()
            ->title($status === 'verified' ? 'Payment marked as verified' : 'Payment marked as failed')
            ->body("Order #{$order->id}")
            ->send();
    }
}
