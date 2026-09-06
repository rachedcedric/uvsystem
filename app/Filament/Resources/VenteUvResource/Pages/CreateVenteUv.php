<?php

namespace App\Filament\Resources\VenteUvResource\Pages;

use App\Filament\Resources\VenteUvResource;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateVenteUv extends CreateRecord
{
    protected static string $resource = VenteUvResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Assigner automatiquement le Master (user connecté)
        $data['user_id'] = Auth::id();

        return $data;
    }

    protected function afterCreate(): void
    {
        // Notification de succès avec info dette
        $dette = $this->record->agentPartenaire->detteAgent->dette_restante ?? 0;

        Notification::make()
            ->success()
            ->title('Vente UV enregistrée')
            ->body("Dette actuelle de {$this->record->agentPartenaire->nom_entreprise}: ".number_format($dette, 0, ',', ' ').' FCFA')
            ->send();
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
