<?php

namespace App\Http\Middleware;

use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Protège l'interface mobile (/m) : il faut être connecté et avoir le droit
 * d'accéder au panneau admin (mêmes rôles que /master).
 */
class MobileAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->guest(route('m.login'));
        }

        abort_unless($user->canAccessPanel(Filament::getPanel('admin')), 403, 'Accès refusé.');

        return $next($request);
    }
}
