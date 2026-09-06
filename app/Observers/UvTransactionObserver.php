<?php

namespace App\Observers;

use App\Models\AgentSolde;
use App\Models\UvTransaction;
use App\Services\FilamentNotificationService;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Log;

class UvTransactionObserver
{
    public function created(UvTransaction $transaction): void
    {
        $this->updateAgentSolde($transaction);
        $this->updateAgentCurrentDebt($transaction);
        $this->checkDebtAlert($transaction);

        // Notification Filament pour nouvelle transaction
        if ($transaction->transaction_type === 'distribution' && $transaction->agent) {
            FilamentNotificationService::transactionCreated(
                $transaction->agent,
                $transaction->amount
            );
        }
    }

    public function updated(UvTransaction $transaction): void
    {
        $this->updateAgentSolde($transaction);
        $this->updateAgentCurrentDebt($transaction);
    }

    public function deleted(UvTransaction $transaction): void
    {
        $this->updateAgentSolde($transaction);
        $this->updateAgentCurrentDebt($transaction);
    }

    protected function updateAgentSolde(UvTransaction $transaction): void
    {
        $agent = $transaction->agent;

        if (! $agent) {
            return;
        }

        AgentSolde::recalculate($agent);
    }

    protected function updateAgentCurrentDebt(UvTransaction $transaction): void
    {
        $agent = $transaction->agent;

        if ($agent) {
            $agent->recalculateDebt();
        }
    }

    protected function checkDebtAlert(UvTransaction $transaction): void
    {
        $agent = $transaction->agent;

        if (! $agent || $transaction->transaction_type !== 'distribution') {
            return;
        }

        // Vérifier si on doit envoyer une alerte
        if ($agent->isDebtCritical() || $agent->isDebtWarning()) {
            try {
                app(NotificationService::class)->checkAndSendDebtAlert($agent);
            } catch (\Exception $e) {
                Log::error('Erreur envoi alerte dette', [
                    'agent_id' => $agent->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
