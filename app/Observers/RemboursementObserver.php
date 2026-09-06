<?php

namespace App\Observers;

use App\Models\AgentSolde;
use App\Models\Remboursement;
use App\Services\FilamentNotificationService;

class RemboursementObserver
{
    public function created(Remboursement $remboursement): void
    {
        $this->updateAgentSolde($remboursement);
        $this->updateAgentCurrentDebt($remboursement);

        // Notification Filament pour nouveau remboursement
        if ($remboursement->agent) {
            FilamentNotificationService::remboursementReceived(
                $remboursement->agent,
                $remboursement->amount
            );
        }
    }

    public function updated(Remboursement $remboursement): void
    {
        $this->updateAgentSolde($remboursement);
        $this->updateAgentCurrentDebt($remboursement);
    }

    public function deleted(Remboursement $remboursement): void
    {
        $this->updateAgentSolde($remboursement);
        $this->updateAgentCurrentDebt($remboursement);
    }

    protected function updateAgentSolde(Remboursement $remboursement): void
    {
        $agent = $remboursement->agent;

        if (! $agent) {
            return;
        }

        AgentSolde::recalculate($agent);
    }

    protected function updateAgentCurrentDebt(Remboursement $remboursement): void
    {
        $agent = $remboursement->agent;

        if ($agent) {
            $agent->recalculateDebt();
        }
    }
}
