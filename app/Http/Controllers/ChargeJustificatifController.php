<?php

namespace App\Http\Controllers;

use App\Models\Charge;
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
        abort_unless(request()->user()?->can('view_charges'), 403);

        if (! $charge->justificatif_path || ! Storage::disk('local')->exists($charge->justificatif_path)) {
            abort(404, 'Justificatif introuvable');
        }

        return Storage::disk('local')->response($charge->justificatif_path);
    }
}
