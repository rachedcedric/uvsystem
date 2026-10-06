<?php

namespace App\Notifications\Subscriptions;

class SubscriptionPlanChanged extends SubscriptionNotification
{
    public static function type(): string
    {
        return 'subscription_plan_changed';
    }

    protected function subject(): string
    {
        return 'Votre offre a changé';
    }

    protected function lines(): array
    {
        return [
            "Votre abonnement est passé à l'offre {$this->plan()} (cycle {$this->cycle()}).",
            'Vos données existantes sont conservées.',
        ];
    }
}
