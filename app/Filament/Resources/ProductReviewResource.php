<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AuthorizesResourceAccess;
use App\Filament\Resources\ProductReviewResource\Pages;
use App\Models\ProductReview;
use App\Support\MediaUrl;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\HtmlString;

class ProductReviewResource extends Resource
{
    use AuthorizesResourceAccess;

    protected static ?string $model = ProductReview::class;

    protected static ?string $navigationIcon = 'heroicon-o-star';

    protected static ?string $navigationGroup = 'Engagement';

    protected static ?string $navigationLabel = 'Reviews';

    protected static ?string $modelLabel = 'review';

    protected static string $permissionKey = 'product_reviews.manage';

    public static function getNavigationBadge(): ?string
    {
        $pending = ProductReview::query()->where('status', ProductReview::PENDING)->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Reviews waiting to be read';
    }

    private static function stars(?int $rating): string
    {
        $rating = max(0, min(5, (int) $rating));

        return str_repeat('★', $rating).str_repeat('☆', 5 - $rating);
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('The review')
                    ->description('Reviews come from customers who ordered the piece, and cannot be written or edited here. You decide whether one is shown, and you can reply to it.')
                    ->schema([
                        Forms\Components\Placeholder::make('product_name')
                            ->label('Product')
                            ->content(fn (?ProductReview $record): string => (string) $record?->product?->name),
                        Forms\Components\Placeholder::make('rating_stars')
                            ->label('Rating')
                            ->content(fn (?ProductReview $record): string => self::stars($record?->rating).' ('.$record?->rating.' of 5)'),
                        Forms\Components\Placeholder::make('reviewer')
                            ->label('Shown as')
                            ->content(fn (?ProductReview $record): string => (string) $record?->name),
                        Forms\Components\Placeholder::make('order_ref')
                            ->label('Order')
                            ->content(fn (?ProductReview $record): string => $record?->order_id ? '#'.$record->order_id.' (verified purchase)' : 'Order no longer on file'),
                        Forms\Components\Placeholder::make('review_title')
                            ->label('Title')
                            ->content(fn (?ProductReview $record): string => (string) ($record?->title ?: '—'))
                            ->columnSpanFull(),
                        Forms\Components\Placeholder::make('review_body')
                            ->label('Review')
                            ->content(fn (?ProductReview $record): HtmlString => new HtmlString('<div style="white-space:pre-line">'.e((string) $record?->body).'</div>'))
                            ->columnSpanFull(),
                        Forms\Components\Placeholder::make('review_photos')
                            ->label('Photos')
                            ->content(function (?ProductReview $record): HtmlString {
                                $images = collect($record?->photos ?? [])->map(fn (array $photo): string => '<a href="'.e((string) MediaUrl::resolve($photo['url'] ?? null)).'" target="_blank" rel="noopener"><img src="'.e((string) MediaUrl::resolve($photo['thumb'] ?? ($photo['url'] ?? null))).'" alt="" style="height:120px;width:auto;border-radius:4px;margin-right:8px"></a>');

                                return new HtmlString($images->isEmpty() ? '—' : $images->implode(''));
                            })
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
                Forms\Components\Section::make('Your reply')
                    ->description('Shown under the review on the product page, once the review is approved. Write as the host: plain, warm and short.')
                    ->schema([
                        Forms\Components\Textarea::make('admin_reply')
                            ->label('Reply')
                            ->rows(4)
                            ->maxLength(1000)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ProductReview::STATUSES[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        ProductReview::PENDING => 'warning',
                        ProductReview::APPROVED => 'success',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('product.name')
                    ->label('Product')
                    ->searchable()
                    ->limit(30),
                Tables\Columns\TextColumn::make('rating')
                    ->formatStateUsing(fn (int $state): string => self::stars($state))
                    ->sortable(),
                Tables\Columns\TextColumn::make('name')
                    ->label('Shown as')
                    ->searchable(),
                Tables\Columns\TextColumn::make('body')
                    ->label('Review')
                    ->limit(60)
                    ->searchable(),
                Tables\Columns\IconColumn::make('admin_reply')
                    ->label('Replied')
                    ->boolean()
                    ->getStateUsing(fn (ProductReview $record): bool => filled($record->admin_reply)),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Received')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(ProductReview::STATUSES),
                Tables\Filters\SelectFilter::make('rating')->options([5 => '5 stars', 4 => '4 stars', 3 => '3 stars', 2 => '2 stars', 1 => '1 star']),
                Tables\Filters\SelectFilter::make('product_id')->label('Product')->relationship('product', 'name')->searchable()->preload(),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('Open'),
                Tables\Actions\Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (ProductReview $record): bool => $record->status !== ProductReview::APPROVED)
                    ->action(fn (ProductReview $record) => self::moderate($record, ProductReview::APPROVED)),
                Tables\Actions\Action::make('reject')
                    ->label('Reject')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (ProductReview $record): bool => $record->status !== ProductReview::REJECTED)
                    ->action(fn (ProductReview $record) => self::moderate($record, ProductReview::REJECTED)),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('approveSelected')
                        ->label('Approve selected')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->action(fn (Collection $records) => $records->each(fn (ProductReview $review) => self::moderate($review, ProductReview::APPROVED, false)))
                        ->deselectRecordsAfterCompletion(),
                    Tables\Actions\BulkAction::make('rejectSelected')
                        ->label('Reject selected')
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->action(fn (Collection $records) => $records->each(fn (ProductReview $review) => self::moderate($review, ProductReview::REJECTED, false)))
                        ->deselectRecordsAfterCompletion(),
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('No reviews yet')
            ->emptyStateDescription('Reviews from customers who ordered a piece appear here to be read before they are shown.');
    }

    public static function moderate(ProductReview $review, string $status, bool $notify = true): void
    {
        $review->moderate($status);

        if ($notify) {
            Notification::make()
                ->success()
                ->title($status === ProductReview::APPROVED ? 'Review approved' : 'Review rejected')
                ->send();
        }
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProductReviews::route('/'),
            'edit' => Pages\EditProductReview::route('/{record}/edit'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
