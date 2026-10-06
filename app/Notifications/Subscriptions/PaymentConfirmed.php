<?php

namespace App\Notifications\Subscriptions;

class PaymentConfirmed extends SubscriptionNotification
{
    public static function type(): string
    {
        return 'payment_confirmed';
    }

    protected function subject(): string
    {
        return 'Paiement confirmé';
    }

    protected function lines(): array
    {
        return [
            "Nous avons bien reçu votre paiement de {$this->amount()} pour l'offre {$this->plan()}.",
            "Votre abonnement est valable jusqu'au {$this->date()}.",
        ];
    }
}
