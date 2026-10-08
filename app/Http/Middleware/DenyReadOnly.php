<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Security\ReadOnlyGuard;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Routes d'export et de téléchargement : refusées aux comptes en lecture seule (une extraction de données est une
 * sortie sensible, même quand la consultation à l'écran est permise).
 */
class DenyReadOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_if($user instanceof User && $user->isReadOnly(), 403, ReadOnlyGuard::message());

        return $next($request);
    }
}
