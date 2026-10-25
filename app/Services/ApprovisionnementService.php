<?php

namespace App\Services;

use App\Models\Approvisionnement;
use App\Models\Fournisseur;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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

    /**
     * @throws ValidationException si le fournisseur est l'entrée « Retour Agent » : un retour d'UV n'est pas un
     *                             approvisionnement (voir RetourAgentService), sinon les UV seraient comptés deux fois.
     */
    public function create(array $data): Approvisionnement
    {
        $fournisseur = isset($data['fournisseur_id']) ? Fournisseur::find($data['fournisseur_id']) : null;

        if ($fournisseur?->isRetourAgent()) {
            throw ValidationException::withMessages([
                'fournisseur_id' => 'Un retour d\'UV d\'un agent s\'enregistre avec « Retour Agent » et le choix de l\'agent, pas comme un approvisionnement.',
            ]);
        }

        return DB::transaction(function () use ($data) {
            $this->masterSoldeService->lockLedgerForUpdate();

            // ApprovisionnementObserver capture solde_uv_avant/solde_uv_apres
            // (creating()) et resynchronise l'instantané master_soldes
            // (created()) — désormais à l'intérieur du verrou ci-dessus.
            return Approvisionnement::create($data);
        });
    }
}
