<?php

namespace App\Filament\Widgets;

use App\Models\Order;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class OrderStatsOverview extends BaseWidget
{
    protected static bool $isLazy = false;

    protected function getStats(): array
    {
        $paidStatuses = ['processing', 'shipped', 'delivered'];

        $totalSales = Order::query()->whereIn('status', $paidStatuses)->sum('total');
        $totalOrders = Order::query()->count();
        $todaySales = Order::query()
            ->whereIn('status', $paidStatuses)
            ->whereDate('created_at', today())
            ->sum('total');

        return [
            Stat::make('Total Sales', '৳'.number_format($totalSales))
                ->description('Processing, shipped & delivered orders')
                ->icon('heroicon-o-banknotes')
                ->color('success'),
            Stat::make('Total Orders', number_format($totalOrders))
                ->icon('heroicon-o-shopping-cart')
                ->color('gray'),
            Stat::make("Today's Sales", '৳'.number_format($todaySales))
                ->icon('heroicon-o-calendar-days')
                ->color('warning'),
        ];
    }
}
