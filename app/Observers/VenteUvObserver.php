<?php

namespace App\Observers;

use App\Models\DetteAgent;
use App\Models\VenteUv;
use App\Tenancy\TenantContext;

class VenteUvObserver
{
    /**
     * Handle the VenteUv "created" event.
     */
    public function created(VenteUv $venteUv): void
    {
        $this->updateDetteAgent($venteUv->agent_partenaire_id);
    }

    /**
     * Handle the VenteUv "updated" event.
     */
    public function updated(VenteUv $venteUv): void
    {
        $this->updateDetteAgent($venteUv->agent_partenaire_id);
    }

    /**
     * Handle the VenteUv "deleted" event.
     */
    public function deleted(VenteUv $venteUv): void
    {
        $this->updateDetteAgent($venteUv->agent_partenaire_id);
    }

    /**
     * Handle the VenteUv "restored" event.
     */
    public function restored(VenteUv $venteUv): void
    {
        $this->updateDetteAgent($venteUv->agent_partenaire_id);
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
