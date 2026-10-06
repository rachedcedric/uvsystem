<?php

namespace App\Notifications\Subscriptions;

class SubscriptionChangeRequested extends SubscriptionNotification
{
    public static function type(): string
    {
        return 'subscription_change_requested';
    }

    protected function subject(): string
    {
        return 'Demande de changement d\'offre';
    }

    protected function lines(): array
    {
        $due = (int) ($this->context['amount'] ?? 0);

        return [
            "Une demande de passage à l'offre {$this->plan()} ({$this->cycle()}) a été enregistrée.",
            $due > 0
                ? "Montant à régler : {$this->amount()} (prorata déduit). Le changement s'applique dès la réception du paiement."
                : 'Aucun montant à régler : le changement est appliqué immédiatement.',
        ];
    }
}
