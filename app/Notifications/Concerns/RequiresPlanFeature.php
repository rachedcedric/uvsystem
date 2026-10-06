<?php

namespace App\Notifications\Concerns;

use App\Services\Subscriptions\SubscriptionEntitlements;

/**
 * Une notification soumise à une fonctionnalité de l'offre ne part qu'à un destinataire dont le tenant l'inclut.
 * Appliqué dans via() : tous les points d'envoi (services, commandes, actions manuelles) sont couverts d'un coup.
 * Destinataire sans tenant (plateforme) : jamais bridé.
 */
trait RequiresPlanFeature
{
    abstract protected function requiredFeature(): string;

    protected function planAllows(object $notifiable): bool
    {
        $tenantId = $notifiable->tenant_id ?? null;

        return $tenantId === null || app(SubscriptionEntitlements::class)->hasFeature((int) $tenantId, $this->requiredFeature());
    }
}
