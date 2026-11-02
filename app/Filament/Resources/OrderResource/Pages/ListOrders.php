<?php

namespace App\Filament\Resources\OrderResource\Pages;

use App\Filament\Resources\OrderResource;
use App\Models\Order;
use App\Support\WalletPayments;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListOrders extends ListRecords
{
    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }

    /**
     * A quick filter beside the table filters: every order, or only those whose payment is waiting for you.
     */
    public function getTabs(): array
    {
        $waiting = Order::query()->where('payment_status', WalletPayments::AWAITING)->count();
        $preorders = Order::query()->where('status', 'preorder')->count();

        return [
            'all' => Tab::make('All orders'),
            'preorders' => Tab::make('Pre-orders')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'preorder'))
                ->badge($preorders > 0 ? $preorders : null)
                ->badgeColor('preorder'),
            'to_verify' => Tab::make('Payment to verify')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('payment_status', WalletPayments::AWAITING))
                ->badge($waiting > 0 ? $waiting : null)
                ->badgeColor('warning'),
        ];
    }
}
