<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\Filament\AgentPanelProvider;
use App\Providers\Filament\PlatformPanelProvider;

return [
    AppServiceProvider::class,
    AdminPanelProvider::class,
    AgentPanelProvider::class,
    PlatformPanelProvider::class,
];
