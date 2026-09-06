<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\AgentSolde;
use App\Models\Fournisseur;
use App\Models\UvTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UvDistributionService
{
    public function __construct(
        protected MasterSoldeService $masterSoldeService = new MasterSoldeService
    ) {}

    /**
     * Point d'entrée unique et sécurisé pour enregistrer une vente UV
     * (distribution à un agent). Toute création de vente doit passer par ici :
     * c'est la seule logique qui vérifie ET débite le Solde UV Disponible de
     * façon atomique.
     *
     * Séquence : verrou du grand livre → vérification du solde → création de
     * la vente (avec solde avant/après pour la traçabilité) → commit.
     * En cas d'erreur (solde insuffisant, agent invalide, plafond de dette
     * dépassé), tout est annulé (rollback) et aucune vente n'est enregistrée.
     *
     * @throws ValidationException
     */
    public function distribute(
        Agent $agent,
        float $amount,
        ?Fournisseur $fournisseur = null,
        ?string $transactionDate = null,
        ?string $notes = null
    ): UvTransaction {
        // Validations qui ne nécessitent pas le verrou (échec rapide).
        $this->validateAgentStatus($agent);
        $this->validateDebtLimit($agent, $amount);

        return DB::transaction(function () use ($agent, $amount, $fournisseur, $transactionDate, $notes) {
            // 1. Verrouiller le grand livre du Solde UV pour toute la durée de
            // la transaction : une vente concurrente devra attendre ce commit
            // avant de pouvoir lire/débiter le solde à son tour.
            $this->masterSoldeService->lockLedgerForUpdate();

            // 2. Vérifier le solde, à l'intérieur du verrou (donc à jour et
            // non partageable avec une autre vente en cours).
            $soldeAvant = $this->masterSoldeService->getAvailableBalance();

            if ($amount > $soldeAvant) {
                throw ValidationException::withMessages([
                    'amount' => sprintf(
                        'Solde UV insuffisant pour effectuer cette vente. Disponible : %s FCFA, demandé : %s FCFA.',
                        number_format($soldeAvant, 0, ',', ' '),
                        number_format($amount, 0, ',', ' ')
                    ),
                ]);
            }

            // 3. Débiter le solde en créant la vente, avec traçabilité.
            $transaction = UvTransaction::create([
                'agent_id' => $agent->id,
                'fournisseur_id' => $fournisseur?->id,
                'amount' => $amount,
                'transaction_type' => 'distribution',
                'transaction_date' => $transactionDate ?? now(),
                'notes' => $notes,
                'status' => 'completed',
                'solde_uv_avant' => $soldeAvant,
                'solde_uv_apres' => $soldeAvant - $amount,
            ]);

            $this->ensureSoldeExists($agent);

            // 4. Garder l'instantané persisté (master_soldes) cohérent pour
            // les lecteurs legacy, toujours à l'intérieur du verrou.
            $this->masterSoldeService->syncPersistedSnapshot();

            return $transaction;
        });
    }

    /**
     * Enregistre un retour d'UV d'un agent : recrédite le Solde UV Disponible.
     */
    public function processReturn(
        Agent $agent,
        float $amount,
        ?string $transactionDate = null,
        ?string $notes = null
    ): UvTransaction {
        return DB::transaction(function () use ($agent, $amount, $transactionDate, $notes) {
            $this->masterSoldeService->lockLedgerForUpdate();

            $soldeAvant = $this->masterSoldeService->getAvailableBalance();
            $montant = abs($amount);

            $transaction = UvTransaction::create([
                'agent_id' => $agent->id,
                'amount' => $montant,
                'transaction_type' => 'retour',
                'transaction_date' => $transactionDate ?? now(),
                'notes' => $notes,
                'status' => 'completed',
                'solde_uv_avant' => $soldeAvant,
                'solde_uv_apres' => $soldeAvant + $montant,
            ]);

            $this->masterSoldeService->syncPersistedSnapshot();

            return $transaction;
        });
    }

    /**
     * Annule une vente déjà enregistrée. N'a de sens que si l'annulation
     * correspond à une restitution réelle des UV à l'agent (les UV vendus
     * n'ont finalement pas été distribués/utilisés) : dans ce cas, marquer la
     * vente "cancelled" l'exclut automatiquement du calcul du Solde UV
     * Disponible, qui remonte donc mécaniquement du montant de la vente.
     *
     * Ne jamais appeler cette méthode pour un simple remboursement financier
     * (dette agent réglée en FCFA) : celui-ci n'implique aucun retour d'UV et
     * ne doit jamais recréditer le Solde UV Disponible.
     *
     * @throws ValidationException
     */
    public function cancel(UvTransaction $transaction): UvTransaction
    {
        if ($transaction->transaction_type !== 'distribution') {
            throw ValidationException::withMessages([
                'transaction' => 'Seule une vente (distribution) peut être annulée avec restitution UV.',
            ]);
        }

        if ($transaction->status === 'cancelled') {
            throw ValidationException::withMessages([
                'transaction' => 'Cette vente est déjà annulée.',
            ]);
        }

        return DB::transaction(function () use ($transaction) {
            $this->masterSoldeService->lockLedgerForUpdate();

            $transaction->forceFill(['status' => 'cancelled'])->save();

            $this->masterSoldeService->syncPersistedSnapshot();

            return $transaction->fresh();
        });
    }

    /**
     * Validate that the agent is active.
     *
     * @throws ValidationException
     */
    protected function validateAgentStatus(Agent $agent): void
    {
        if ($agent->status !== 'active') {
            throw ValidationException::withMessages([
                'agent_id' => "L'agent \"{$agent->name}\" n'est pas actif. Statut actuel: {$agent->status}",
            ]);
        }
    }

    /**
     * Validate that the distribution won't exceed the agent's debt limit.
     *
     * @throws ValidationException
     */
    protected function validateDebtLimit(Agent $agent, float $amount): void
    {
        // Skip validation if no debt limit is set (0 or null means unlimited)
        if (empty($agent->debt_limit) || $agent->debt_limit <= 0) {
            return;
        }

        $currentDebt = $this->getCurrentDebt($agent);
        $projectedDebt = $currentDebt + $amount;

        if ($projectedDebt > $agent->debt_limit) {
            $availableCredit = max(0, $agent->debt_limit - $currentDebt);

            throw ValidationException::withMessages([
                'amount' => sprintf(
                    "Cette distribution dépasserait le plafond de dette autorisé.\n".
                    "• Dette actuelle: %s FCFA\n".
                    "• Montant demandé: %s FCFA\n".
                    "• Plafond autorisé: %s FCFA\n".
                    '• Crédit disponible: %s FCFA',
                    number_format($currentDebt, 0, ',', ' '),
                    number_format($amount, 0, ',', ' '),
                    number_format($agent->debt_limit, 0, ',', ' '),
                    number_format($availableCredit, 0, ',', ' ')
                ),
            ]);
        }
    }

    /**
     * Get the current debt for an agent.
     */
    public function getCurrentDebt(Agent $agent): float
    {
        $solde = $agent->solde ?? AgentSolde::where('agent_id', $agent->id)->first();

        return $solde ? (float) $solde->solde_restant : 0;
    }

    /**
     * Get the available credit for an agent.
     */
    public function getAvailableCredit(Agent $agent): float
    {
        if (empty($agent->debt_limit) || $agent->debt_limit <= 0) {
            return PHP_FLOAT_MAX; // Unlimited
        }

        return max(0, $agent->debt_limit - $this->getCurrentDebt($agent));
    }

    /**
     * Check if an agent can receive a distribution of a given amount.
     */
    public function canDistribute(Agent $agent, float $amount): bool
    {
        if ($agent->status !== 'active') {
            return false;
        }

        if (empty($agent->debt_limit) || $agent->debt_limit <= 0) {
            return true; // No limit
        }

        return $this->getAvailableCredit($agent) >= $amount;
    }

    /**
     * Validate distribution and return result array (non-throwing version).
     */
    public function validateDistribution(Agent $agent, float $amount): array
    {
        // Check agent status
        if ($agent->status !== 'active') {
            return [
                'valid' => false,
                'message' => "L'agent \"{$agent->name}\" n'est pas actif. Statut actuel: {$agent->status}",
            ];
        }

        // Check debt limit
        if (! empty($agent->debt_limit) && $agent->debt_limit > 0) {
            $currentDebt = $this->getCurrentDebt($agent);
            $projectedDebt = $currentDebt + $amount;

            if ($projectedDebt > $agent->debt_limit) {
                $availableCredit = max(0, $agent->debt_limit - $currentDebt);

                return [
                    'valid' => false,
                    'message' => sprintf(
                        'Cette distribution dépasserait le plafond de dette autorisé. Crédit disponible: %s FCFA',
                        number_format($availableCredit, 0, ',', ' ')
                    ),
                    'available_credit' => $availableCredit,
                ];
            }
        }

        return ['valid' => true, 'message' => 'Distribution autorisée'];
    }

    /**
     * Ensure the agent has a solde record.
     */
    protected function ensureSoldeExists(Agent $agent): AgentSolde
    {
        return AgentSolde::firstOrCreate(
            ['agent_id' => $agent->id],
            [
                'total_uv_distribues' => 0,
                'total_rembourse' => 0,
                'solde_restant' => 0,
            ]
        );
    }

    /**
     * Get distribution statistics for an agent.
     */
    public function getAgentStats(Agent $agent): array
    {
        $solde = $agent->solde ?? $this->ensureSoldeExists($agent);

        return [
            'total_uv_distribues' => (float) $solde->total_uv_distribues,
            'total_rembourse' => (float) $solde->total_rembourse,
            'solde_restant' => (float) $solde->solde_restant,
            'debt_limit' => (float) ($agent->debt_limit ?? 0),
            'available_credit' => $this->getAvailableCredit($agent),
            'debt_ratio' => $agent->debt_limit > 0
                ? round(($solde->solde_restant / $agent->debt_limit) * 100, 2)
                : 0,
            'is_at_limit' => $this->getAvailableCredit($agent) <= 0,
            'transactions_count' => $agent->transactions()->count(),
            'remboursements_count' => $agent->remboursements()->count(),
        ];
    }
}
