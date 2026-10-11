<?php

namespace App\Filament\Pages;

use App\Filament\Resources\ProductResource;
use App\Http\Controllers\FeedController;
use App\Models\SiteSetting;
use App\Support\Feeds\FeedBuilder;
use Filament\Actions\Action;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

class ManageProductFeeds extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-rss';

    protected static ?string $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Product feeds';

    protected static ?string $title = 'Product feeds';

    protected static string $view = 'filament.pages.manage-product-feeds';

    protected static ?string $slug = 'product-feeds';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return Auth::user()?->hasPermission('product_feeds.manage') ?? false;
    }

    public function mount(): void
    {
        $site = SiteSetting::query()->firstOrCreate([]);
        $site->feedToken();

        $this->form->fill(['feeds_include_out_of_stock' => (bool) $site->feeds_include_out_of_stock]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Toggle::make('feeds_include_out_of_stock')
                    ->label('Include out-of-stock items, marked as out of stock')
                    ->helperText('On: a sold-out product stays in the feeds as "out of stock", so a dynamic ad can stop showing it. Off: it is left out. Saved as you switch it.')
                    ->live()
                    ->afterStateUpdated(function (bool $state): void {
                        SiteSetting::query()->firstOrCreate([])->update(['feeds_include_out_of_stock' => $state]);
                        FeedController::forgetAll();

                        Notification::make()->success()->title('Saved')->send();
                    }),
            ])
            ->statePath('data');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('rebuild')
                ->label('Rebuild feeds now')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->action(function (): void {
                    FeedController::forgetAll();

                    Notification::make()->success()->title('The feeds will be rebuilt the next time they are fetched')->send();
                }),
            Action::make('regenerateToken')
                ->label('Regenerate token')
                ->icon('heroicon-o-key')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Regenerate the feed token?')
                ->modalDescription('Every feed address changes. Anything still using an old address (Meta, Google or TikTok) stops receiving the feed until you give it the new one.')
                ->modalSubmitActionLabel('Regenerate')
                ->action(function (): void {
                    SiteSetting::query()->firstOrCreate([])->regenerateFeedToken();

                    Notification::make()->success()->title('New token made')->body('Copy the new addresses into Meta, Google and TikTok.')->send();
                }),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $site = SiteSetting::query()->firstOrCreate([]);
        $token = $site->feedToken();
        $result = (new FeedBuilder($site))->build();

        $feeds = collect([
            'google' => ['label' => 'Google Merchant Center', 'note' => 'RSS 2.0 (XML)', 'route' => 'feeds.google'],
            'meta' => ['label' => 'Meta (Facebook and Instagram)', 'note' => 'CSV', 'route' => 'feeds.meta'],
            'tiktok' => ['label' => 'TikTok catalog', 'note' => 'CSV', 'route' => 'feeds.tiktok'],
        ])->map(fn (array $feed, string $platform): array => $feed + [
            'url' => route($feed['route'], ['token' => $token]),
            'download' => route($feed['route'], ['token' => $token, 'download' => 1]),
            'count' => count(FeedBuilder::forPlatform($result['items'], $platform)),
        ])->all();

        $health = collect($result['health'])->map(fn (array $row): array => $row + [
            'edit' => ProductResource::getUrl('edit', ['record' => $row['product_id']]),
        ])->all();

        return [
            'feeds' => $feeds,
            'health' => $health,
            'storefront' => $site->feedStorefrontUrl(),
            'itemCount' => count($result['items']),
        ];
    }
}
