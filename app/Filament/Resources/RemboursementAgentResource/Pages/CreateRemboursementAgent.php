<?php

namespace App\Filament\Resources\RemboursementAgentResource\Pages;

use App\Filament\Resources\RemboursementAgentResource;
use App\Models\DetteAgent;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateRemboursementAgent extends CreateRecord
{
    protected static string $resource = RemboursementAgentResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Valider que le montant ne dépasse pas la dette
        $dette = DetteAgent::where('agent_partenaire_id', $data['agent_partenaire_id'])->first();

        if ($dette && $data['montant'] > $dette->dette_restante) {
            Notification::make()
                ->warning()
                ->title('Montant trop élevé')
                ->body('Le montant saisi ('.number_format($data['montant'], 0, ',', ' ').' FCFA) dépasse la dette actuelle ('.number_format($dette->dette_restante, 0, ',', ' ').' FCFA)')
                ->persistent()
                ->send();

            $this->halt();
        }

        // Nettoyer les champs techniques
        unset($data['_dette_actuelle']);
        unset($data['_dette_montant']);
        unset($data['_validation_message']);

        return $data;
    }

    protected function afterCreate(): void
    {
        // Récupérer la dette mise à jour après remboursement
        $dette = DetteAgent::where('agent_partenaire_id', $this->record->agent_partenaire_id)->first();
        $detteRestante = $dette->dette_restante ?? 0;

        // Notification selon le statut
        if ($detteRestante == 0) {
            Notification::make()
                ->success()
                ->title('✅ Dette soldée !')
                ->body("{$this->record->agentPartenaire->nom_entreprise} n'a plus de dette. Compte à jour !")
                ->duration(10000)
                ->send();
        } else {
            Notification::make()
                ->success()
                ->title('Remboursement enregistré')
                ->body('Reste à payer: '.number_format($detteRestante, 0, ',', ' ').' FCFA')
                ->send();
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
