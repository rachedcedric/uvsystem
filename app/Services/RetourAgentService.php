<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\Approvisionnement;
use App\Models\Fournisseur;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Retour Agent (rebalancing) : un agent rend des UV au Master, qui les remet en stock et les lui doit. Saisi depuis
 * le formulaire d'approvisionnement avec le fournisseur « Retour Agent » et le choix de l'agent réel.
 *
 * Traité exactement comme un approvisionnement « Agent Premium » (à crédit), sur le compte fournisseur propre à
 * l'agent (Fournisseur::pourAgent, créé automatiquement au premier retour) :
 *   - Solde UV Disponible du Master + montant (MasterSoldeService : approvisionnements − ventes + retours) ;
 *   - dette du Master envers l'agent + montant : visible dans « Dettes fournisseurs », payable, annulable, avec
 *     découvert et compensation comme tout fournisseur à crédit (FournisseurDebtService) ;
 *   - la dette de l'agent envers le Master (ses achats d'UV) ne change pas : une seule écriture, aucune double
 *     comptabilisation. Les commissions (calculées sur les ventes) ne changent pas.
 */
class RetourAgentService
{
    public function __construct(
        protected ApprovisionnementService $approvisionnements
    ) {}

    /**
     * @throws ValidationException
     */
    public function enregistrer(Agent|int|null $agent, float $amount, ?string $date = null, ?string $notes = null): Approvisionnement
    {
        $agent = $this->resolveAgent($agent);

        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Le montant retourné doit être supérieur à 0.']);
        }

        return DB::transaction(fn () => $this->approvisionnements->create([
            'user_id' => Auth::id(),
            'fournisseur_id' => Fournisseur::pourAgent($agent)->id,
            'amount' => $amount,
            'date_approvisionnement' => $date ?? now(),
            'notes' => $notes ?? 'Retour d\'UV de l\'agent '.$agent->name,
        ]));
    }

    /**
     * Effet prévisible d'un retour sur ce que le Master doit à l'agent (aperçu avant validation).
     *
     * @return array{du_avant: float, du_apres: float}
     */
    public function impact(Agent $agent, float $amount): array
    {
        $compte = Fournisseur::query()->where('agent_id', $agent->getKey())->first();
        $avant = $compte ? app(FournisseurDebtService::class)->detteRestante($compte) : 0.0;

        return ['du_avant' => $avant, 'du_apres' => $avant + max(0, $amount)];
    }

    /**
     * Seuls les agents de l'entreprise courante sont acceptés : un identifiant d'une autre entreprise est introuvable
     * (scope d'entreprise) et un modèle d'une autre entreprise passé directement est refusé.
     *
     * @throws ValidationException
     */
    private function resolveAgent(Agent|int|null $agent): Agent
    {
        $resolved = $agent instanceof Agent ? $agent : ($agent ? Agent::query()->find($agent) : null);

        if (! $resolved) {
            throw ValidationException::withMessages(['agent_id' => 'Sélectionnez un agent de votre entreprise.']);
        }

        $tenantId = TenantContext::id();

        if ($tenantId !== null && (int) $resolved->tenant_id !== (int) $tenantId) {
            throw ValidationException::withMessages(['agent_id' => 'Cet agent n\'appartient pas à votre entreprise.']);
        }

        return $resolved;
    }
}
