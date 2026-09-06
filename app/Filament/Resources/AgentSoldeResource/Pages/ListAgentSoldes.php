<?php

namespace App\Filament\Resources\AgentSoldeResource\Pages;

use App\Filament\Resources\AgentSoldeResource;
use App\Models\Agent;
use App\Models\AgentSolde;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListAgentSoldes extends ListRecords
{
    protected static string $resource = AgentSoldeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('recalculate_all_agents')
                ->label('Recalculer Tous les Agents')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Recalculer tous les soldes')
                ->modalDescription('Cette action recalculera automatiquement les soldes de TOUS les agents en fonction de leurs transactions et remboursements réels. Cela peut prendre quelques secondes.')
                ->modalSubmitActionLabel('Oui, recalculer tout')
                ->action(function () {
                    $agents = Agent::all();
                    $count = 0;

                    foreach ($agents as $agent) {
                        AgentSolde::recalculate($agent);
                        $count++;
                    }

                    Notification::make()
                        ->title('Recalcul terminé !')
                        ->body("$count soldes d'agents ont été recalculés avec succès.")
                        ->success()
                        ->duration(5000)
                        ->send();
                }),

            Actions\CreateAction::make()
                ->label('Créer Manuellement')
                ->icon('heroicon-o-plus')
                ->modalHeading('Créer un solde manuellement')
                ->modalDescription('⚠️ Les soldes sont normalement créés automatiquement. N\'utilisez cette option que pour des corrections ou données historiques.'),
        ];
    }
}
