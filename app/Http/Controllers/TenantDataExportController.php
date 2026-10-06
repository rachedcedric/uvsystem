<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantDataExporter;
use Illuminate\Support\Str;

class TenantDataExportController extends Controller
{
    /**
     * Export complet des données du tenant de l'utilisateur. Réservé au `master` (propriétaire du
     * compte société) ; le tenant vient de l'utilisateur connecté, jamais de la requête.
     */
    public function download(TenantDataExporter $exporter)
    {
        $user = request()->user();

        abort_unless($user && $user->hasRole('master') && $user->hasActiveTenant(), 403);

        $zip = $exporter->build();

        activity()->causedBy($user)->log('Export complet des données du tenant');

        $name = Str::slug(Tenant::find(TenantContext::requireId())?->name ?? 'donnees').'-donnees-'.now()->format('Y-m-d').'.zip';

        return response()->download($zip, $name, ['Content-Type' => 'application/zip'])->deleteFileAfterSend(true);
    }
}
