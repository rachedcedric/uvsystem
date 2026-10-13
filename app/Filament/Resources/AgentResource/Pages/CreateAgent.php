<?php

namespace App\Filament\Resources\AgentResource\Pages;

use App\Filament\Resources\AgentResource;
use App\Services\Subscriptions\SubscriptionEntitlements;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateAgent extends CreateRecord
{
    protected static string $resource = AgentResource::class;

    /** Message clair avant de tenter l'écriture ; le vrai refus reste celui du modèle (SubscriptionEntitlements). */
    protected function beforeCreate(): void
    {
        $reason = app(SubscriptionEntitlements::class)->agentCreationRefusal();

        if ($reason !== null) {
            Notification::make()->title('Création impossible')->body($reason)->danger()->send();
            $this->halt();
        }
    }
}
