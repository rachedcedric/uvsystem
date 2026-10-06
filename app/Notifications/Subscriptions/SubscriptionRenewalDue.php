<?php

namespace App\Notifications\Subscriptions;

class SubscriptionRenewalDue extends SubscriptionNotification
{
    public static function type(): string
    {
        return 'subscription_renewal_due';
    }

    protected function subject(): string
    {
        return 'Votre renouvellement est à régler';
    }

    protected function lines(): array
    {
        return [
            "Votre abonnement {$this->plan()} est arrivé à échéance. Une facture de {$this->amount()} est en attente de règlement.",
            'Dès réception du paiement, votre abonnement est prolongé. Vos données sont conservées et consultables en attendant.',
        ];
    }
}
