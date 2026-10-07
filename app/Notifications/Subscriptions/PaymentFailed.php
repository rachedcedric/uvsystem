<?php

namespace App\Notifications\Subscriptions;

class PaymentFailed extends SubscriptionNotification
{
    public static function type(): string
    {
        return 'payment_failed';
    }

    public static function label(): string
    {
        return 'Paiement non abouti';
    }

    public static function defaults(): array
    {
        return [
            'subject' => 'Paiement non abouti',
            'lines' => [
                'Votre paiement de {amount} pour l\'offre {plan} n\'a pas abouti.',
                'Aucun montant n\'est compté ; votre abonnement n\'a pas été modifié.',
            ],
        ];
    }
}
