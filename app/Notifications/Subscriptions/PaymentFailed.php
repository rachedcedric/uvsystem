<?php

namespace App\Notifications\Subscriptions;

class PaymentFailed extends SubscriptionNotification
{
    public static function type(): string
    {
        return 'payment_failed';
    }

    protected function subject(): string
    {
        return 'Paiement non abouti';
    }

    protected function lines(): array
    {
        return [
            "Votre paiement de {$this->amount()} pour l'offre {$this->plan()} n'a pas abouti.",
            "Aucun montant n'est compté ; votre abonnement n'a pas été modifié.",
        ];
    }
}
