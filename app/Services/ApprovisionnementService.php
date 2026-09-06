<?php

namespace App\Services;

use App\Models\Approvisionnement;
use Illuminate\Support\Facades\DB;

/**
 * Point d'entrée unique et sécurisé pour enregistrer un approvisionnement.
 * Verrouille le grand livre du Solde UV Disponible avant la création, pour
 * que le solde_uv_avant/solde_uv_apres capturé par ApprovisionnementObserver
 * soit garanti exact même si un autre approvisionnement ou une vente est en
 * cours de traitement au même instant (même garantie que
 * UvDistributionService::distribute()).
 */
class ApprovisionnementService
{
    public function __construct(
        protected MasterSoldeService $masterSoldeService = new MasterSoldeService
    ) {}

    public function create(array $data): Approvisionnement
    {
        return DB::transaction(function () use ($data) {
            $this->masterSoldeService->lockLedgerForUpdate();

            // ApprovisionnementObserver capture solde_uv_avant/solde_uv_apres
            // (creating()) et resynchronise l'instantané master_soldes
            // (created()) — désormais à l'intérieur du verrou ci-dessus.
            return Approvisionnement::create($data);
        });
    }
}
