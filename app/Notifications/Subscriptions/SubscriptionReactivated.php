<?php

namespace App\Notifications\Subscriptions;

class SubscriptionReactivated extends SubscriptionNotification
{
    public static function type(): string
    {
        return 'subscription_reactivated';
    }

    protected function subject(): string
    {
        return 'Votre abonnement est réactivé';
    }

    protected function lines(): array
    {
        return [
            "Votre abonnement {$this->plan()} est de nouveau actif.",
            'Vous pouvez de nouveau enregistrer des opérations.',
        ];
    }
}
