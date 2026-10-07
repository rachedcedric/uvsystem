<?php

namespace App\Notifications\Subscriptions;

class TrialStarted extends SubscriptionNotification
{
    public static function type(): string
    {
        return 'trial_started';
    }

    public static function label(): string
    {
        return 'Essai démarré';
    }

    public static function defaults(): array
    {
        return [
            'subject' => 'Votre essai gratuit a démarré',
            'lines' => [
                'Votre période d\'essai de l\'offre {plan} est active jusqu\'au {date}.',
                'Toutes les fonctions de l\'offre sont disponibles pendant l\'essai.',
            ],
        ];
    }
}
