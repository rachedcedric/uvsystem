<?php

namespace App\Notifications\Subscriptions;

class SubscriptionExpired extends SubscriptionNotification
{
    public static function type(): string
    {
        return 'subscription_expired';
    }

    protected function subject(): string
    {
        return 'Votre abonnement a expiré';
    }

    protected function lines(): array
    {
        return [
            "Votre abonnement {$this->plan()} a expiré le {$this->date()}.",
            "Vos données sont intactes et consultables, ainsi que les exports. Les modifications sont suspendues jusqu'à la réactivation par l'administrateur.",
        ];
    }
}
