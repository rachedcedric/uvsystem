<?php

namespace App\Notifications\Subscriptions;

class SubscriptionRenewalDue extends SubscriptionNotification
{
    public static function type(): string
    {
        return 'subscription_renewal_due';
    }

    public static function label(): string
    {
        return 'Renouvellement à régler';
    }

    public static function defaults(): array
    {
        return [
            'subject' => 'Votre renouvellement est à régler',
            'lines' => [
                'Votre abonnement {plan} est arrivé à échéance. Une facture de {amount} est en attente de règlement.',
                'Dès réception du paiement, votre abonnement est prolongé. Vos données sont conservées et consultables en attendant.',
            ],
        ];
    }
}
