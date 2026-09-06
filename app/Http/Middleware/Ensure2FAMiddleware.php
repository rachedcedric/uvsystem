<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware Ensure2FA
 *
 * Force les utilisateurs Master/Admin à activer le 2FA s'ils ne l'ont pas encore fait.
 * Redirige vers la page de configuration 2FA si nécessaire.
 */
class Ensure2FAMiddleware
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = Auth::user();

        // Si pas d'utilisateur connecté, continuer
        if (! $user) {
            return $next($request);
        }

        // Vérifier si l'utilisateur est Master ou Admin
        $requires2FA = $user->hasAnyRole(['master', 'admin']);

        // Si 2FA pas requis, continuer
        if (! $requires2FA) {
            return $next($request);
        }

        // Vérifier si le 2FA est activé
        if (! $user->two_factor_enabled || ! $user->two_factor_secret) {
            // Exclure la route de configuration 2FA elle-même
            if (! $request->routeIs('filament.admin.pages.two-factor-auth-page')) {
                // Rediriger vers la page de configuration 2FA
                session()->flash('warning', 'En tant qu\'administrateur, vous devez activer l\'authentification à deux facteurs pour continuer.');

                return redirect()->route('filament.admin.pages.two-factor-auth-page');
            }
        }

        return $next($request);
    }
}
