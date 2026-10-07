<?php

namespace App\Notifications\Subscriptions;

class TrialExpiring extends SubscriptionNotification
{
    public static function type(): string
    {
        return 'trial_expiring';
    }

    public static function label(): string
    {
        return 'Fin d\'essai proche';
    }

    public static function defaults(): array
    {
        return [
            'subject' => 'Votre essai se termine dans {days} jour(s)',
            'lines' => [
                'Votre essai de l\'offre {plan} se termine le {date}.',
                'Passez à un abonnement pour continuer à enregistrer des opérations ; vos données sont conservées dans tous les cas.',
            ],
        ];
    }
}
