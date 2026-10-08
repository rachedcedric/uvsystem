<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
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
            \Illuminate\Http\Middleware\HandleCors::class,
        ]);

        // Invité sur une route web protégée => page de connexion Filament (il n'existe pas de route "login" nommée).
        $middleware->redirectGuestsTo(fn () => route('filament.admin.auth.login'));

        // Stateful API pour Sanctum (frontend SPA)
        $middleware->statefulApi();

        // Middleware de sécurité pour les transactions financières
        $middleware->alias([
            'transaction.security' => \App\Http\Middleware\CheckTransactionSecurity::class,
            'mobile.access' => \App\Http\Middleware\MobileAccess::class,
            'staff' => \App\Http\Middleware\EnsureStaffUser::class,
            'not.read-only' => \App\Http\Middleware\DenyReadOnly::class,
            '2fa' => \App\Http\Middleware\EnforceTwoFactor::class,
        ]);

        // Exclure les webhooks de la vérification CSRF
        $middleware->validateCsrfTokens(except: [
            'api/webhooks/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
