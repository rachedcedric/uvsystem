<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use Filament\Resources\Pages\CreateRecord;

class CreateUser extends CreateRecord
{
    use ChecksRoleAssignment;

    protected static string $resource = UserResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    /** Message clair avant de tenter l'écriture ; le vrai refus reste celui du modèle (SubscriptionEntitlements). */
    protected function beforeCreate(): void
    {
        $this->assertRolesAllowed();

        $reason = app(\App\Services\Subscriptions\SubscriptionEntitlements::class)->userCreationRefusal();

        if ($reason !== null) {
            \Filament\Notifications\Notification::make()->title('Création impossible')->body($reason)->danger()->send();
            $this->halt();
        }
    }
}
