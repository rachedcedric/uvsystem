<?php

namespace App\Notifications\Subscriptions;

class SubscriptionReactivated extends SubscriptionNotification
{
    public static function type(): string
    {
        return 'subscription_reactivated';
    }

    public static function label(): string
    {
        return 'Abonnement réactivé';
    }

    public static function defaults(): array
    {
        return [
            'subject' => 'Votre abonnement est réactivé',
            'lines' => [
                'Votre abonnement {plan} est de nouveau actif.',
                'Vous pouvez de nouveau enregistrer des opérations.',
            ],
        ];
    }
}
