<?php

namespace App\Notifications\Subscriptions;

class TrialStarted extends SubscriptionNotification
{
    public static function type(): string
    {
        return 'trial_started';
    }

    protected function subject(): string
    {
        return 'Votre essai gratuit a démarré';
    }

    protected function lines(): array
    {
        return [
            "Votre période d'essai de l'offre {$this->plan()} est active jusqu'au {$this->date()}.",
            "Toutes les fonctions de l'offre sont disponibles pendant l'essai.",
        ];
    }
}
