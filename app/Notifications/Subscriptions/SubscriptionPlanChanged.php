<?php

namespace App\Notifications\Subscriptions;

class SubscriptionPlanChanged extends SubscriptionNotification
{
    public static function type(): string
    {
        return 'subscription_plan_changed';
    }

    public static function label(): string
    {
        return 'Offre changée';
    }

    public static function defaults(): array
    {
        return [
            'subject' => 'Votre offre a changé',
            'lines' => [
                'Votre abonnement est passé à l\'offre {plan} (cycle {cycle}).',
                'Vos données existantes sont conservées.',
            ],
        ];
    }
}
