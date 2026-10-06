<?php

namespace App\Notifications\Subscriptions;

class TrialExpiring extends SubscriptionNotification
{
    public static function type(): string
    {
        return 'trial_expiring';
    }

    protected function subject(): string
    {
        return "Votre essai se termine dans {$this->days()} jour(s)";
    }

    protected function lines(): array
    {
        return [
            "Votre essai de l'offre {$this->plan()} se termine le {$this->date()}.",
            'Passez à un abonnement pour continuer à enregistrer des opérations ; vos données sont conservées dans tous les cas.',
        ];
    }
}
