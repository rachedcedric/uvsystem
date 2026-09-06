<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\AgentSolde;
use App\Models\Remboursement;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RemboursementService
{
    /**
     * Process a repayment from an agent.
     *
     * @throws ValidationException
     */
    public function process(
        Agent $agent,
        float $amount,
        string $paymentMethod = 'cash',
        ?string $paymentDate = null,
        ?string $notes = null,
        ?string $reference = null
    ): Remboursement {
        // Validate the repayment amount
        $this->validateAmount($agent, $amount);

        return DB::transaction(function () use ($agent, $amount, $paymentMethod, $paymentDate, $notes, $reference) {
            $remboursement = Remboursement::create([
                'agent_id' => $agent->id,
                'amount' => $amount,
                'payment_method' => $paymentMethod,
                'reference' => $reference,
                // now() (pas toDateString()) : un remboursement sans date
                // explicite doit garder son heure réelle, pas être ramené à
                // minuit (impacterait tri et filtrage "aujourd'hui").
                'payment_date' => $paymentDate ?? now(),
                'notes' => $notes,
            ]);

            // Solde is automatically recalculated via Observer

            return $remboursement;
        });
    }

    /**
     * Validate the repayment amount.
     *
     * @throws ValidationException
     */
    protected function validateAmount(Agent $agent, float $amount): void
    {
        if ($amount <= 0) {
            throw ValidationException::withMessages([
                'amount' => 'Le montant du remboursement doit être supérieur à 0.',
            ]);
        }

        $currentDebt = $this->getCurrentDebt($agent);

        if ($amount > $currentDebt) {
            throw ValidationException::withMessages([
                'amount' => sprintf(
                    'Le montant du remboursement ne peut pas dépasser le reste à payer. (Demandé : %s FCFA, reste à payer : %s FCFA)',
                    number_format($amount, 0, ',', ' '),
                    number_format($currentDebt, 0, ',', ' ')
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
     * Check if an agent has any outstanding debt.
     */
    public function hasDebt(Agent $agent): bool
    {
        return $this->getCurrentDebt($agent) > 0;
    }

    /**
     * Validate remboursement and return result array (non-throwing version).
     */
    public function validateRemboursement(Agent $agent, float $amount): array
    {
        if ($amount <= 0) {
            return [
                'valid' => false,
                'message' => 'Le montant du remboursement doit être supérieur à 0.',
            ];
        }

        $currentDebt = $this->getCurrentDebt($agent);

        if ($currentDebt <= 0) {
            return [
                'valid' => false,
                'message' => "L'agent n'a pas de dette en cours.",
            ];
        }

        if ($amount > $currentDebt) {
            return [
                'valid' => false,
                'message' => sprintf(
                    'Le montant du remboursement (%s FCFA) dépasse la dette actuelle (%s FCFA).',
                    number_format($amount, 0, ',', ' '),
                    number_format($currentDebt, 0, ',', ' ')
                ),
                'current_debt' => $currentDebt,
            ];
        }

        return ['valid' => true, 'message' => 'Remboursement autorisé'];
    }

    /**
     * Full repayment of all agent's debt.
     */
    public function fullRepayment(
        Agent $agent,
        string $paymentMethod = 'cash',
        ?string $notes = null
    ): ?Remboursement {
        $currentDebt = $this->getCurrentDebt($agent);

        if ($currentDebt <= 0) {
            return null; // No debt to repay
        }

        return $this->process(
            $agent,
            $currentDebt,
            $paymentMethod,
            null,
            $notes ?? 'Remboursement intégral de la dette'
        );
    }

    /**
     * Get repayment history for an agent.
     */
    public function getHistory(Agent $agent, int $limit = 10): array
    {
        return $agent->remboursements()
            ->orderBy('payment_date', 'desc')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'reference' => $r->reference,
                'amount' => (float) $r->amount,
                'payment_method' => $r->payment_method,
                'payment_date' => $r->payment_date,
                'notes' => $r->notes,
            ])
            ->toArray();
    }

    /**
     * Get repayment statistics for an agent.
     */
    public function getStats(Agent $agent): array
    {
        $solde = $agent->solde;

        return [
            'total_rembourse' => $solde ? (float) $solde->total_rembourse : 0,
            'current_debt' => $this->getCurrentDebt($agent),
            'remboursements_count' => $agent->remboursements()->count(),
            'average_remboursement' => (float) $agent->remboursements()->avg('amount') ?? 0,
            'last_remboursement' => $agent->remboursements()->latest('payment_date')->first()?->payment_date,
            'payment_methods' => $agent->remboursements()
                ->selectRaw('payment_method, COUNT(*) as count, SUM(amount) as total')
                ->groupBy('payment_method')
                ->get()
                ->keyBy('payment_method')
                ->toArray(),
        ];
    }
}
