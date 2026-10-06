<?php

namespace App\Notifications\Subscriptions;

class SubscriptionSuspended extends SubscriptionNotification
{
    public static function type(): string
    {
        return 'subscription_suspended';
    }

    protected function subject(): string
    {
        return 'Votre abonnement est suspendu';
    }

    protected function lines(): array
    {
        return [
            "Votre abonnement {$this->plan()} a été suspendu.",
            "Vos données restent consultables ; les modifications sont suspendues jusqu'à la réactivation.",
        ];
    }
}
