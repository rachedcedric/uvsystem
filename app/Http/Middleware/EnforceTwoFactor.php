<?php

namespace App\Http\Middleware;

use App\Security\TwoFactorGate;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applique la 2FA sur les surfaces web (Filament /admin /agent /platform, mobile /m) :
 *  - 2FA activée mais non prouvée dans cette session / sur cet appareil => écran du code ;
 *  - 2FA obligatoire mais non activée => écran d'activation (jamais de blocage sans issue :
 *    cet écran, le code et la déconnexion restent toujours accessibles).
 */
class EnforceTwoFactor
{
    public function __construct(private TwoFactorGate $gate) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $this->gate->featureEnabled() || $this->isExempt($request)) {
            return $next($request);
        }

        if (! $this->gate->isVerified($request, $user)) {
            // Requête Livewire/JSON : on ne peut pas rediriger un appel XHR, on signale la session expirée.
            abort_if($request->expectsJson() || $request->hasHeader('X-Livewire'), 401, 'Vérification en deux étapes requise.');

            session()->put('2fa_intended_url', $request->fullUrl());

            return redirect()->route('2fa.challenge');
        }

        if ($this->gate->mustEnrollNow($user)) {
            abort_if($request->expectsJson() || $request->hasHeader('X-Livewire'), 403, 'Activez la double authentification.');

            session()->flash('warning', 'La double authentification est obligatoire pour votre rôle : activez-la pour continuer.');

            return redirect()->route('security.2fa');
        }

        return $next($request);
    }

    private function isExempt(Request $request): bool
    {
        return $request->routeIs('2fa.*', 'security.2fa*', 'm.logout', 'm.login', 'filament.*.auth.*')
            || $request->is('m-ping');
    }
}
