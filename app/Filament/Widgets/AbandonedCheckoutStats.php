<?php

namespace App\Filament\Widgets;

use App\Models\AbandonedCheckout;
use App\Models\Order;
use App\Support\Dhaka;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

/**
 * How many checkouts were left, how many came back after a reminder, and what they were worth. A checkout counts as left
 * when it did not order on its own; recovered when it ordered after we had written to it.
 */
class AbandonedCheckoutStats extends StatsOverviewWidget
{
    protected static ?string $pollingInterval = '60s';

    protected function getStats(): array
    {
        $left = AbandonedCheckout::query()->where('status', '!=', AbandonedCheckout::STATUS_ORDERED)->count();
        $recovered = AbandonedCheckout::query()->where('status', AbandonedCheckout::STATUS_RECOVERED)->count();
        $revenue = (int) Order::query()
            ->whereIn('id', AbandonedCheckout::query()->where('status', AbandonedCheckout::STATUS_RECOVERED)->whereNotNull('order_id')->select('order_id'))
            ->where('status', '!=', 'cancelled')
            ->sum('total');

        $uncontactable = (int) DB::table('checkout_daily_counts')
            ->where('day', '>=', Dhaka::day(now()->subDays(29)))
            ->whereIn('stage', ['cart', 'checkout_started', 'contact_filled'])
            ->sum('visitors');

        return [
            Stat::make('Abandoned', number_format($left))
                ->description('Left without ordering, and reachable by email or phone'),
            Stat::make('Recovered', number_format($recovered))
                ->description($left > 0 ? round($recovered / $left * 100).'% of those left' : 'Ordered after a reminder')
                ->color('success'),
            Stat::make('Revenue recovered', '৳'.number_format($revenue))
                ->description('Orders placed after a reminder')
                ->color('success'),
            Stat::make('Reached the cart with no contact (30 days)', number_format($uncontactable))
                ->description('Only counted, with nothing stored. Someone who then gave an email or phone number is also in the list above.'),
        ];
    }
}
