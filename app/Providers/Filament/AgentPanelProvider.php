<?php

namespace App\Providers\Filament;

use App\Filament\Agent\Pages\AgentDashboard;
use App\Filament\Agent\Widgets\AgentStatsOverview;
use App\Http\Middleware\EnforceTwoFactor;
use App\Support\BrandAsset;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Assets\Css;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AgentPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('agent')
            ->path('agent')
            ->login()
            ->colors([
                'primary' => Color::Emerald,
            ])
            ->assets([
                Css::make('compact-ui', asset('css/filament-compact.css')),
            ])
            ->brandName(config('branding.name').' - Portail Agent')
            ->brandLogo(asset('images/logo.png'))
            ->darkModeBrandLogo(asset('images/logo-dark.png'))
            ->brandLogoHeight('2.5rem')
            ->favicon(BrandAsset::url('favicon-32.png'))
            // Pont Capacitor (APK Android) : bouton retour natif et lien « ← Application » ; inactif dans un navigateur classique.
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn (): string => '<script src="'.e(asset('js/capacitor-bridge.js')).'?v='
                    .(@filemtime(public_path('js/capacitor-bridge.js')) ?: 1).'"></script>',
            )
            ->discoverResources(in: app_path('Filament/Agent/Resources'), for: 'App\\Filament\\Agent\\Resources')
            ->discoverPages(in: app_path('Filament/Agent/Pages'), for: 'App\\Filament\\Agent\\Pages')
            ->pages([
                AgentDashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Agent/Widgets'), for: 'App\\Filament\\Agent\\Widgets')
            ->widgets([
                AgentStatsOverview::class,
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
                EnforceTwoFactor::class,
            ])
            ->authGuard('web');
    }
}
