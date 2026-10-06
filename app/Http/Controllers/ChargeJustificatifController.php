<?php

namespace App\Http\Controllers;

use App\Models\Charge;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Storage;

/**
 * Sert le justificatif d'une charge depuis le disque local (jamais exposé
 * publiquement) — même motif que ExportDownloadController, plus une
 * vérification de permission explicite (une Page Filament personnalisée
 * n'applique pas les Policies automatiquement, contrairement à une Resource).
 */
class ChargeJustificatifController extends Controller
{
    public function download(Charge $charge)
    {
        $this->authorize('view', $charge);

        $path = (string) $charge->justificatif_path;

        // Défense en profondeur : le chemin stocké doit rester dans le dossier du tenant. Seuls les
        // fichiers antérieurs au multi-tenant (charges/…) du tenant d'origine sont tolérés, en attendant
        // `php artisan tenancy:relocate-files`.
        $allowed = str_starts_with($path, 'tenants/'.TenantContext::requireId().'/')
            || (TenantContext::requireId() === Tenant::legacyOwnerId() && str_starts_with($path, 'charges/'));

        if ($path === '' || str_contains($path, '..') || ! $allowed || ! Storage::disk('local')->exists($path)) {
            abort(404, 'Justificatif introuvable');
        }

        return Storage::disk('local')->response($path);
    }
}
