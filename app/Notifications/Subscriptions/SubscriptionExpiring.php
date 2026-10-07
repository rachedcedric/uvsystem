<?php

namespace App\Notifications\Subscriptions;

class SubscriptionExpiring extends SubscriptionNotification
{
    public static function type(): string
    {
        return 'subscription_expiring';
    }

    public static function label(): string
    {
        return 'Abonnement bientôt échu';
    }

    public static function defaults(): array
    {
        return [
            'subject' => 'Votre abonnement expire dans {days} jour(s)',
            'lines' => [
                'Votre abonnement {plan} arrive à échéance le {date}.',
                'Pensez à le renouveler pour éviter le passage en consultation seule.',
            ],
        ];
    }
}
