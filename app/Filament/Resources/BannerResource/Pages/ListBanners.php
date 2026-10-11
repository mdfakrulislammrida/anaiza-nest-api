<?php

namespace App\Filament\Resources\BannerResource\Pages;

use App\Filament\Resources\BannerResource;
use App\Models\SiteSetting;
use Filament\Actions;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListBanners extends ListRecords
{
    protected static string $resource = BannerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('sliderSettings')
                ->label('Slider settings')
                ->icon('heroicon-o-cog-6-tooth')
                ->color('gray')
                ->modalHeading('Slider settings')
                ->modalDescription('Up to five slides can show. With one slide there are no arrows or dots. The slider stays still until you switch autoplay on.')
                ->fillForm(fn (): array => [
                    'slider_autoplay' => (bool) SiteSetting::query()->first()?->slider_autoplay,
                    'slider_interval' => SiteSetting::sliderInterval(),
                ])
                ->form([
                    Toggle::make('slider_autoplay')
                        ->label('Move to the next slide by itself')
                        ->helperText('Off by default. It pauses while a visitor hovers or touches it, and never moves for visitors who ask their device for less motion.'),
                    TextInput::make('slider_interval')
                        ->label('Seconds on each slide')
                        ->helperText('Slow on purpose: at least 6 seconds.')
                        ->numeric()
                        ->integer()
                        ->minValue(SiteSetting::SLIDER_MIN_INTERVAL)
                        ->maxValue(30)
                        ->required(),
                ])
                ->action(function (array $data): void {
                    SiteSetting::query()->firstOrCreate([])->update([
                        'slider_autoplay' => (bool) $data['slider_autoplay'],
                        'slider_interval' => max(SiteSetting::SLIDER_MIN_INTERVAL, (int) $data['slider_interval']),
                    ]);

                    Notification::make()->success()->title('Slider settings saved')
                        ->body('The storefront is a static build, so a change shows after the next storefront build.')
                        ->send();
                }),
            Actions\CreateAction::make(),
        ];
    }
}
