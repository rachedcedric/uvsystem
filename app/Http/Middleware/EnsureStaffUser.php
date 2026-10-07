<?php

namespace App\Http\Middleware;

use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fail closed : un utilisateur authentifié sans rôle métier (compte orphelin,
 * ancien auto-enregistrement…) n'a accès à aucune route protégée de l'API.
 * Même règle que /master et /m (User::canAccessPanel).
 */
class EnsureStaffUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user && $user->canAccessPanel(Filament::getPanel('admin')), 403, 'Accès refusé.');

        return $next($request);
    }
}
