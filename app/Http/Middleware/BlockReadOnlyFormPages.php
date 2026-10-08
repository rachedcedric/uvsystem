<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Security\ReadOnlyGuard;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Panneau web : un compte en lecture seule n'ouvre aucune page de création ni de modification (…/create, …/edit),
 * y compris pour les ressources dont la politique ne les refuserait pas d'elle-même. Filet de sécurité en plus des
 * permissions : l'écriture reste de toute façon refusée au niveau du modèle (ReadOnlyGuard).
 */
class BlockReadOnlyFormPages
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->isReadOnly() && preg_match('#/(create|edit)$#', $request->path())) {
            abort(403, ReadOnlyGuard::message());
        }

        return $next($request);
    }
}
