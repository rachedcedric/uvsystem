<?php

namespace App\Http\Controllers;

use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportDownloadController extends Controller
{
    /**
     * Télécharger un fichier d'export.
     *
     * Les exports vivent sur le disque privé (jamais sous public/storage) et ne
     * sont servis qu'après vérification d'une permission d'export. Le nom de
     * fichier est réduit à son basename : aucune traversée de dossier possible.
     */
    public function download(string $filename): StreamedResponse
    {
        $user = request()->user();

        abort_unless($user && collect([
            'export_agents', 'export_uv_transactions', 'export_remboursements',
            'export_remboursements_fournisseurs', 'export_commissions', 'export_charges', 'export_reports',
        ])->contains(fn (string $permission) => $user->can($permission)), 403);

        $filename = basename($filename);
        // Le dossier dépend du tenant courant : impossible de nommer un fichier d'un autre tenant.
        $path = TenantContext::storagePath('exports/'.$filename);

        abort_unless(str_ends_with($filename, '.xlsx') && Storage::disk('local')->exists($path), 404, 'Fichier non trouvé');

        return Storage::disk('local')->download($path, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }
}
