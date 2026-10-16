<?php

use App\Http\Middleware\CheckTransactionSecurity;
use App\Http\Middleware\DenyReadOnly;
use App\Http\Middleware\EnforceTwoFactor;
use App\Http\Middleware\EnsureStaffUser;
use App\Http\Middleware\MobileAccess;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // Contrôle de connexion de l'appli mobile : volontairement hors des groupes web/api, donc sans
            // session, sans cookie et sans accès base de données (il est appelé toutes les 30 secondes).
            Route::get('m-ping', fn () => response()->noContent()->header('Cache-Control', 'no-store'))->name('m.ping');

            // Interface mobile de l'APK (Blade + Livewire, sans Filament).
            Route::middleware('web')->group(base_path('routes/mobile.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // CORS pour les requêtes API
        $middleware->api(prepend: [
            HandleCors::class,
        ]);

        // Invité sur une route web protégée => page de connexion Filament (il n'existe pas de route "login" nommée).
        // L'application Android (agent utilisateur « UvmasterApp », voir capacitor.config.json) retrouve sa connexion mobile :
        // sinon une session expirée sur /security/sessions la jetait sur la page de bureau (« Commencer gratuitement »).
        $middleware->redirectGuestsTo(fn (Request $request) => str_contains((string) $request->userAgent(), 'UvmasterApp')
            ? route('m.login')
            : route('filament.admin.auth.login'));

        // Stateful API pour Sanctum (frontend SPA)
        $middleware->statefulApi();

        // Middleware de sécurité pour les transactions financières
        $middleware->alias([
            'transaction.security' => CheckTransactionSecurity::class,
            'mobile.access' => MobileAccess::class,
            'staff' => EnsureStaffUser::class,
            'not.read-only' => DenyReadOnly::class,
            '2fa' => EnforceTwoFactor::class,
        ]);

        // Exclure les webhooks de la vérification CSRF
        $middleware->validateCsrfTokens(except: [
            'api/webhooks/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
