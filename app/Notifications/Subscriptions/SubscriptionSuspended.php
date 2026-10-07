<?php

namespace App\Notifications\Subscriptions;

class SubscriptionSuspended extends SubscriptionNotification
{
    public static function type(): string
    {
        return 'subscription_suspended';
    }

    public static function label(): string
    {
        return 'Abonnement suspendu';
    }

    public static function defaults(): array
    {
        return [
            'subject' => 'Votre abonnement est suspendu',
            'lines' => [
                'Votre abonnement {plan} a été suspendu.',
                'Vos données restent consultables ; les modifications sont suspendues jusqu\'à la réactivation.',
            ],
        ];
    }
}
