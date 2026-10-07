<?php

namespace App\Filament\Resources\ProductReviewResource\Pages;

use App\Filament\Resources\ProductReviewResource;
use App\Models\ProductReview;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListProductReviews extends ListRecords
{
    protected static string $resource = ProductReviewResource::class;

    /**
     * A quick filter above the table: everything, or only what is waiting to be read.
     */
    public function getTabs(): array
    {
        $pending = ProductReview::query()->where('status', ProductReview::PENDING)->count();

        return [
            'all' => Tab::make('All reviews'),
            'pending' => Tab::make('To read')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', ProductReview::PENDING))
                ->badge($pending > 0 ? $pending : null)
                ->badgeColor('warning'),
        ];
    }
}
