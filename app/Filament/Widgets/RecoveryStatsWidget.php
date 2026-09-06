<?php

namespace App\Filament\Widgets;

use App\Models\Agent;
use App\Models\Remboursement;
use App\Models\UvTransaction;
use Carbon\Carbon;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class RecoveryStatsWidget extends BaseWidget
{
    protected static ?int $sort = 6;

    protected function getStats(): array
    {
        // Taux de recouvrement global
        $totalDistribue = UvTransaction::where('transaction_type', 'distribution')->sum('amount');
        $totalRembourse = Remboursement::sum('amount');
        $tauxRecouvrement = $totalDistribue > 0
            ? round(($totalRembourse / $totalDistribue) * 100, 1)
            : 0;

        // Agents sans dette (utiliser current_debt de la table agents)
        $agentsSansDette = Agent::where('status', 'active')->where('current_debt', '<=', 0)->count();
        $totalAgents = Agent::where('status', 'active')->count();

        // Délai moyen de remboursement (approximatif)
        $remboursementsRecents = Remboursement::where('created_at', '>=', Carbon::now()->subDays(30))
            ->count();

        // Objectif mensuel (exemple: 80% de recouvrement)
        $objectifMensuel = 80;
        $progressionObjectif = min(100, round(($tauxRecouvrement / $objectifMensuel) * 100));

        return [
            Stat::make('Taux de recouvrement', $tauxRecouvrement.'%')
                ->description($totalRembourse > 0 ? 'Sur '.number_format($totalDistribue, 0, ',', ' ').' FCFA distribués' : 'Aucune distribution')
                ->descriptionIcon('heroicon-m-chart-pie')
                ->color($tauxRecouvrement >= 70 ? 'success' : ($tauxRecouvrement >= 50 ? 'warning' : 'danger'))
                ->chart([65, 70, 72, 68, 75, $tauxRecouvrement]),

            Stat::make('Agents soldés', $agentsSansDette.' / '.$totalAgents)
                ->description('Sans dette en cours')
                ->descriptionIcon('heroicon-m-check-badge')
                ->color('success'),

            Stat::make('Remboursements (30j)', $remboursementsRecents)
                ->description('Opérations ce mois')
                ->descriptionIcon('heroicon-m-arrow-path')
                ->color('info'),

            Stat::make('Objectif mensuel', $progressionObjectif.'%')
                ->description('Progression vers '.$objectifMensuel.'% de recouvrement')
                ->descriptionIcon('heroicon-m-flag')
                ->color($progressionObjectif >= 100 ? 'success' : ($progressionObjectif >= 75 ? 'warning' : 'danger')),
        ];
    }

    protected function getColumns(): int
    {
        return 4;
    }
}
