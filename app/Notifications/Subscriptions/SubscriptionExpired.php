<?php

namespace App\Notifications\Subscriptions;

class SubscriptionExpired extends SubscriptionNotification
{
    public static function type(): string
    {
        return 'subscription_expired';
    }

    public static function label(): string
    {
        return 'Abonnement expiré';
    }

    public static function defaults(): array
    {
        return [
            'subject' => 'Votre abonnement a expiré',
            'lines' => [
                'Votre abonnement {plan} a expiré le {date}.',
                'Vos données sont intactes et consultables, ainsi que les exports. Les modifications sont suspendues jusqu\'à la réactivation par l\'administrateur.',
            ],
        ];
    }
}
