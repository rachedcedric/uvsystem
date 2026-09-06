<?php

namespace App\Observers;

use App\Models\Approvisionnement;
use App\Services\MasterSoldeService;

class ApprovisionnementObserver
{
    public function __construct(
        protected MasterSoldeService $masterSoldeService
    ) {}

    /**
     * Capture le Solde UV Disponible avant/après pour la traçabilité, avant
     * l'insertion en base.
     */
    public function creating(Approvisionnement $approvisionnement): void
    {
        $soldeAvant = $this->masterSoldeService->getAvailableBalance();

        $approvisionnement->solde_uv_avant = $soldeAvant;
        $approvisionnement->solde_uv_apres = $soldeAvant + (float) $approvisionnement->amount;
    }

    /**
     * Handle the Approvisionnement "created" event.
     * Met à jour le Solde UV Disponible après ajout d'un approvisionnement.
     */
    public function created(Approvisionnement $approvisionnement): void
    {
        $this->masterSoldeService->syncPersistedSnapshot();
    }

    /**
     * Handle the Approvisionnement "updated" event.
     */
    public function updated(Approvisionnement $approvisionnement): void
    {
        $this->masterSoldeService->syncPersistedSnapshot();
    }

    /**
     * Handle the Approvisionnement "deleted" event.
     * Recalculer le solde après suppression soft
     */
    public function deleted(Approvisionnement $approvisionnement): void
    {
        $this->masterSoldeService->syncPersistedSnapshot();
    }

    /**
     * Handle the Approvisionnement "restored" event.
     */
    public function restored(Approvisionnement $approvisionnement): void
    {
        $this->masterSoldeService->syncPersistedSnapshot();
    }
}
