<?php

namespace App\Observers;

use App\Models\Commission;
use App\Models\PalierCommission;
use App\Models\VenteUv;

class CommissionObserver
{
    /**
     * Handle the VenteUv "created" event.
     */
    public function created(VenteUv $venteUv): void
    {
        $this->genererCommission($venteUv);
    }

    /**
     * Générer automatiquement la commission basée sur le montant de vente
     */
    private function genererCommission(VenteUv $venteUv): void
    {
        // Trouver le palier correspondant au montant
        $palier = PalierCommission::findPalierForMontant($venteUv->montant_total);

        if ($palier) {
            // Créer la commission
            Commission::create([
                'vente_uv_id' => $venteUv->id,
                'montant_vente' => $venteUv->montant_total,
                'montant_commission' => $palier->commission,
                'seuil_min' => $palier->seuil_min,
                'seuil_max' => $palier->seuil_max,
                'date_commission' => $venteUv->date_vente ?? today(),
                'notes' => "Commission automatique - Palier {$palier->seuil_min} à {$palier->seuil_max}",
            ]);
        }
    }
}
