<?php

namespace App\Observers;

use App\Models\DetteAgent;
use App\Models\RemboursementAgent;
use App\Tenancy\TenantContext;

class RemboursementAgentObserver
{
    /**
     * Handle the RemboursementAgent "created" event.
     */
    public function created(RemboursementAgent $remboursementAgent): void
    {
        $this->updateDetteAgent($remboursementAgent->agent_partenaire_id);
    }

    /**
     * Handle the RemboursementAgent "updated" event.
     */
    public function updated(RemboursementAgent $remboursementAgent): void
    {
        $this->updateDetteAgent($remboursementAgent->agent_partenaire_id);
    }

    /**
     * Handle the RemboursementAgent "deleted" event.
     */
    public function deleted(RemboursementAgent $remboursementAgent): void
    {
        $this->updateDetteAgent($remboursementAgent->agent_partenaire_id);
    }

    /**
     * Handle the RemboursementAgent "restored" event.
     */
    public function restored(RemboursementAgent $remboursementAgent): void
    {
        $this->updateDetteAgent($remboursementAgent->agent_partenaire_id);
    }

    /**
     * Recalculer automatiquement la dette d'un agent
     */
    private function updateDetteAgent(int $agentPartenaireId): void
    {
        // Calculer le total des achats (ventes)
        $totalAchats = TenantContext::table('ventes_uv')
            ->where('agent_partenaire_id', $agentPartenaireId)
            ->whereNull('deleted_at')
            ->sum('montant_total');

        // Calculer le total remboursé
        $totalRembourse = TenantContext::table('remboursements_agents')
            ->where('agent_partenaire_id', $agentPartenaireId)
            ->whereNull('deleted_at')
            ->sum('montant');

        // Dette restante = achats - remboursements
        $detteRestante = $totalAchats - $totalRembourse;

        // Mettre à jour ou créer l'enregistrement
        DetteAgent::updateOrCreate(
            ['agent_partenaire_id' => $agentPartenaireId],
            [
                'total_achats' => $totalAchats,
                'total_rembourse' => $totalRembourse,
                'dette_restante' => $detteRestante,
            ]
        );
    }
}
