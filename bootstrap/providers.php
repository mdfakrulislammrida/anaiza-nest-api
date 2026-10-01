<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\IntegrationSettingsServiceProvider;

return [
    AppServiceProvider::class,
    IntegrationSettingsServiceProvider::class,
    AdminPanelProvider::class,
];
