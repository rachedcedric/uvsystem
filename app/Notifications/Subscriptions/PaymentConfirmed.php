<?php

namespace App\Notifications\Subscriptions;

class PaymentConfirmed extends SubscriptionNotification
{
    public static function type(): string
    {
        return 'payment_confirmed';
    }

    public static function label(): string
    {
        return 'Paiement confirmé';
    }

    public static function defaults(): array
    {
        return [
            'subject' => 'Paiement confirmé',
            'lines' => [
                'Nous avons bien reçu votre paiement de {amount} pour l\'offre {plan}.',
                'Votre abonnement est valable jusqu\'au {date}.',
            ],
        ];
    }
}
