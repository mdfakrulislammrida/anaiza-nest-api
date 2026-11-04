<?php

namespace App\Providers\Filament;

use App\Filament\Support\UploadPreview;
use App\Models\CourierSetting;
use App\Support\Dhaka;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TimePicker;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Navigation\NavigationGroup;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\View\PanelsRenderHook;
use Filament\Widgets;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    /**
     * Every date and time the admin shows is Dhaka time. The database keeps UTC (and the application stays on UTC, so the stored times, the
     * scheduler, the quiet hours and the emails are untouched); this is only how a time is read on screen. Filament 3.3 has no global display
     * time zone, so the default is given to the columns and entries that print a time (a date-only column is not shifted) and to the pickers
     * that hold a time. One format for all: 04 Nov 2026, 11:14 AM.
     */
    public function boot(): void
    {
        Table::$defaultDateTimeDisplayFormat = Dhaka::DISPLAY;
        Table::$defaultDateDisplayFormat = 'd M Y';
        Infolist::$defaultDateTimeDisplayFormat = Dhaka::DISPLAY;
        Infolist::$defaultDateDisplayFormat = 'd M Y';

        TextColumn::configureUsing(fn (TextColumn $column) => $column->timezone(Dhaka::ZONE));
        TextEntry::configureUsing(fn (TextEntry $entry) => $entry->timezone(Dhaka::ZONE));
        // A date-only or time-only picker is left alone: a Dhaka midnight is the day before in UTC, and the stored date would move back a day.
        DateTimePicker::configureUsing(fn (DateTimePicker $picker) => $picker instanceof DatePicker || $picker instanceof TimePicker ? null : $picker->timezone(Dhaka::ZONE));

        // Every upload field: a small picture drawn from the page's own address (the "important" flag runs it after the field's own defaults).
        FileUpload::configureUsing(fn (FileUpload $upload) => UploadPreview::configure($upload), isImportant: true);
    }

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->colors([
                'primary' => Color::Amber,
                // The Pre-order status badge: a colour no other status uses.
                'preorder' => Color::Violet,
            ])
            // Registered explicitly (rather than relying on each resource's
            // string $navigationGroup alone) so every group can start
            // collapsed -- the plain string form doesn't support that.
            ->navigationGroups([
                NavigationGroup::make('Catalog')->collapsed(),
                NavigationGroup::make('Content')->collapsed(),
                NavigationGroup::make('Engagement')->collapsed(),
                NavigationGroup::make('Sales')->collapsed(),
                NavigationGroup::make('Marketing')->collapsed(),
                NavigationGroup::make('Administration')->collapsed(),
                NavigationGroup::make('Settings')->collapsed(),
            ])
            ->renderHook(
                PanelsRenderHook::STYLES_AFTER,
                fn (): string => view('filament.admin.compact-styles')->render().view('filament.admin.mobile-styles')->render().view('filament.admin.upload-styles')->render(),
            )
            // A banner on every page while Pathao is in its sandbox. Never allowed to break the admin (the table may not exist yet).
            ->renderHook(
                PanelsRenderHook::BODY_START,
                function (): string {
                    try {
                        $settings = CourierSetting::query()->first();

                        return $settings?->pathao_enabled && $settings->pathao_sandbox ? view('filament.admin.courier-sandbox-banner')->render() : '';
                    } catch (\Throwable) {
                        return '';
                    }
                },
            )
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Pages\Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([
                Widgets\AccountWidget::class,
                Widgets\FilamentInfoWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
