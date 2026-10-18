<?php

namespace App\Providers\Filament;

use App\Filament\Platform\Pages\Auth\Login;
use App\Http\Middleware\EnforceTwoFactor;
use App\Support\BrandAsset;
use Filament\Facades\Filament;
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

/**
 * Console de la plateforme SaaS (/platform) : gestion des tenants par le super admin.
 * Accès réservé par User::canAccessPanel() (rôle super_admin ET aucun tenant).
 */
class PlatformPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('platform')
            ->path('platform')
            ->login(Login::class)
            // Vert de la marque (même vert que la page d'accueil).
            ->colors(['primary' => Color::hex('#168447')])
            ->brandName(config('branding.name').' — Plateforme')
            ->brandLogo(BrandAsset::url('images/logo-wide.png'))
            ->brandLogoHeight('2.25rem')
            ->favicon(BrandAsset::url('favicon-32.png'))
            // Icônes d'onglet complètes (.ico + écran d'accueil), versionnées : le navigateur redemande aussi /favicon.ico tout seul.
            ->renderHook(PanelsRenderHook::HEAD_END, fn () => view('partials.favicons'))
            ->font('Inter')
            ->assets([
                Css::make('auth-ui', asset('css/filament-auth.css').'?v='.(@filemtime(public_path('css/filament-auth.css')) ?: 1)),
                Css::make('platform-ui', asset('css/filament-platform.css').'?v='.(@filemtime(public_path('css/filament-platform.css')) ?: 1)),
            ])
            // Panneau de marque de la connexion : visiteurs non connectés seulement (jamais sur les pages de la console).
            ->renderHook(
                PanelsRenderHook::BODY_START,
                fn () => Filament::auth()->guest() ? view('filament.platform.login-brand') : '',
            )
            ->renderHook(
                'panels::auth.login.form.after',
                fn () => '<p class="pf-help"><a href="'.e(route('password.request')).'">Mot de passe oublié ?</a></p>'
                    .'<p class="pf-note">Connexion protégée : '
                    .(config('security.authentication.2fa_enabled', true) ? 'double authentification requise, ' : '')
                    .'tentatives répétées temporairement bloquées.<br>'
                    .'<a href="/" style="color:inherit;text-decoration:underline">Retour au site</a></p>',
            )
            ->discoverResources(in: app_path('Filament/Platform/Resources'), for: 'App\\Filament\\Platform\\Resources')
            ->discoverPages(in: app_path('Filament/Platform/Pages'), for: 'App\\Filament\\Platform\\Pages')
            ->discoverWidgets(in: app_path('Filament/Platform/Widgets'), for: 'App\\Filament\\Platform\\Widgets')
            // Ordre des groupes du menu : chaque élément est rangé dans un groupe (aucun élément isolé, aucune fusion).
            ->navigationGroups(['Pilotage', 'Entreprises', 'Abonnements', 'CMS', 'Système'])
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
            ->authMiddleware([Authenticate::class, EnforceTwoFactor::class])
            ->authGuard('web');
    }
}
