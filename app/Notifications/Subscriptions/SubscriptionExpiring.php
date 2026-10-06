<?php

namespace App\Notifications\Subscriptions;

class SubscriptionExpiring extends SubscriptionNotification
{
    public static function type(): string
    {
        return 'subscription_expiring';
    }

    protected function subject(): string
    {
        return "Votre abonnement expire dans {$this->days()} jour(s)";
    }

    protected function lines(): array
    {
        return [
            "Votre abonnement {$this->plan()} arrive à échéance le {$this->date()}.",
            'Pensez à le renouveler pour éviter le passage en consultation seule.',
        ];
    }
}
