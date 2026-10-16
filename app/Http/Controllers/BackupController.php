<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BackupController extends Controller
{
    /**
     * Télécharger une sauvegarde.
     *
     * Réservé aux rôles master et admin, et limité aux archives .zip du
     * dossier de sauvegarde : sans ces contrôles, tout utilisateur connecté
     * pouvait lire n'importe quel fichier du disque privé (path traversal).
     */
    public function download(string $path): StreamedResponse
    {
        abort_unless(request()->user()?->isSuperAdmin(), 403); // Les sauvegardes contiennent les données de TOUS les tenants : réservées au super admin de plateforme.

        $disk = Storage::disk('local');
        $directory = config('app.name', 'UVMASTER CI');
        $decodedPath = $directory.'/'.basename((string) base64_decode($path, true));

        if (! Str::endsWith($decodedPath, '.zip') || ! $disk->exists($decodedPath)) {
            abort(404, 'Backup not found');
        }

        return $disk->download($decodedPath, basename($decodedPath));
    }
}
