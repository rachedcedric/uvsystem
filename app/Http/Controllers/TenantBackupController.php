<?php

namespace App\Http\Controllers;

use App\Services\Subscriptions\SubscriptionEntitlements;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Storage;

/** Téléchargement d'une sauvegarde dédiée du tenant de l'utilisateur connecté (master, offre avec « sauvegardes_dediees »). */
class TenantBackupController extends Controller
{
    public function download(string $file)
    {
        $user = request()->user();
        abort_unless($user && $user->hasRole('master') && $user->hasActiveTenant(), 403);
        app(SubscriptionEntitlements::class)->abortUnlessFeature('sauvegardes_dediees');
        abort_unless(preg_match('/^backup-\d{8}-\d{6}\.zip$/', $file) === 1, 404);

        // Le dossier vient du tenant de l'utilisateur, jamais de la requête : impossible de lire celui d'un autre.
        $path = TenantContext::storagePath("backups/{$file}");
        abort_unless(Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->download($path, $file, ['Content-Type' => 'application/zip']);
    }
}
