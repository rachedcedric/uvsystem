<?php

namespace App\Providers\Filament;

use App\Http\Middleware\EnforceTwoFactor;
use App\Http\Middleware\TriggerDebtReminderCycle;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
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

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('master')
            ->login(\App\Filament\Pages\Auth\Login::class)
            ->brandName('MODELEC CI')
            ->brandLogo(asset('images/logo.png'))
            ->darkModeBrandLogo(asset('images/logo-dark.png'))
            ->brandLogoHeight('2.5rem')
            ->favicon(asset('favicon-32.png'))
            ->colors([
                'primary' => Color::Blue,
                'danger' => Color::Rose,
                'warning' => Color::Orange,
                'success' => Color::Emerald,
                'info' => Color::Sky,
            ])
            ->assets([
                Css::make('compact-ui', asset('css/filament-compact.css')),
                Css::make('dashboard-ui', asset('css/filament-dashboard.css').'?v='.(@filemtime(public_path('css/filament-dashboard.css')) ?: 1)),
                Css::make('auth-ui', asset('css/filament-auth.css').'?v='.(@filemtime(public_path('css/filament-auth.css')) ?: 1)),
            ])
            ->font('Inter')
            // Un compte lié à une fiche agent (master compris) retrouve son espace agent.
            ->navigationItems([
                \Filament\Navigation\NavigationItem::make('Mon espace agent')
                    ->icon('heroicon-o-user-circle')
                    ->group('Mon activité')
                    ->sort(1)
                    ->url('/agent') // chemin du panneau « agent » (AgentPanelProvider) ; pas de Filament::getPanel() ici : il relance la construction du menu
                    ->visible(fn (): bool => auth()->user()?->agent()->exists() ?? false),
            ])
            ->navigationGroups([
                NavigationGroup::make()
                    ->label('Gestion Agents')
                    ->icon('heroicon-o-users'),
                NavigationGroup::make()
                    ->label('Transactions')
                    ->icon('heroicon-o-currency-dollar'),
                NavigationGroup::make()
                    ->label('Gestion des Charges')
                    ->icon('heroicon-o-receipt-percent'),
                NavigationGroup::make()
                    ->label('Gestion Approvisionnement')
                    ->icon('heroicon-o-truck'),
                NavigationGroup::make()
                    ->label('Analytics')
                    ->icon('heroicon-o-chart-bar'),
                NavigationGroup::make()
                    ->label('Mon Compte')
                    ->icon('heroicon-o-user-circle'),
                NavigationGroup::make()
                    ->label('Données du tenant')
                    ->icon('heroicon-o-folder-open'),
                NavigationGroup::make()
                    ->label('Gestion des Ventes')
                    ->icon('heroicon-o-shopping-cart'),
                NavigationGroup::make()
                    ->label('Notifications du tenant')
                    ->icon('heroicon-o-cog-8-tooth'),
                NavigationGroup::make()
                    ->label('Paramètres du tenant')
                    ->icon('heroicon-o-cog-6-tooth'),
                NavigationGroup::make()
                    ->label('Gestion du tenant')
                    ->icon('heroicon-o-wrench-screwdriver'),
            ])
            ->sidebarCollapsibleOnDesktop()
            ->sidebarFullyCollapsibleOnDesktop()
            ->globalSearchKeyBindings(['command+k', 'ctrl+k'])
            ->globalSearchFieldKeyBindingSuffix()
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            // App\Filament\Pages\Dashboard (notre page d'accueil personnalisée)
            // vit dans ce dossier et est donc récupérée automatiquement ici,
            // sans registration explicite (qui la dupliquerait).
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            // Enregistre tous les widgets du dossier auprès de Livewire (y
            // compris ceux utilisés uniquement sur d'autres pages, ex:
            // RemboursementsStatsWidget). Le tableau de bord lui-même
            // n'affiche que le sous-ensemble curé défini dans
            // App\Filament\Pages\Dashboard::getWidgets().
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->databaseNotifications()
            ->databaseNotificationsPolling('30s')
            // Connexion : panneau de marque (visiteurs non connectés) et logo au-dessus du titre (page de connexion seulement).
            ->renderHook(
                PanelsRenderHook::BODY_START,
                fn () => \Filament\Facades\Filament::auth()->guest() ? view('filament.admin.login-brand') : '',
            )
            ->renderHook(
                PanelsRenderHook::SIMPLE_PAGE_START,
                fn () => '<img class="pf-logo" src="'.e(asset('images/logo-wide.png')).'" alt="MODELEC CI">',
                scopes: \App\Filament\Pages\Auth\Login::class,
            )
            ->renderHook(
                'panels::auth.login.form.after',
                fn () => '<p class="pf-help"><a href="'.e(route('password.request')).'">Mot de passe oublié ?</a></p>'
                    .'<p class="pf-note">Pas encore de compte ? <a class="pf-link" href="'.e(route('offres', [], false)).'">Commencer gratuitement</a><br>'
                    .'<a href="'.e(route('m.login', [], false)).'" style="color:inherit;text-decoration:underline">Accès mobile</a> · '
                    .'<a href="/" style="color:inherit;text-decoration:underline">Retour au site</a></p>',
            )
            ->renderHook(
                PanelsRenderHook::BODY_START,
                fn () => view('components.two-factor-reminder'),
            )
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn (): string => view('filament.hooks.notification-sound')->render(),
            )
            // Pont Capacitor (APK Android) : inactif dans un navigateur classique.
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn (): string => '<script src="'.e(asset('js/capacitor-bridge.js')).'?v='
                    .(@filemtime(public_path('js/capacitor-bridge.js')) ?: 1).'"></script>',
            )
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
                TriggerDebtReminderCycle::class,
                EnforceTwoFactor::class,
            ]);
    }
}
