<?php

namespace App\Filament\Widgets;

use App\Models\Agent;
use App\Models\UvTransaction;
use App\Services\MasterSoldeService;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class VentesUvStatsWidget extends BaseWidget
{
    protected static ?int $sort = 2;

    protected function getStats(): array
    {
        $masterService = new MasterSoldeService;

        // Solde Master disponible
        $soldeDisponible = $masterService->getAvailableBalance();

        // Statistiques du jour
        $todayAmount = UvTransaction::whereDate('transaction_date', today())
            ->where('transaction_type', 'distribution')
            ->sum('amount');
        $todayCount = UvTransaction::whereDate('transaction_date', today())
            ->where('transaction_type', 'distribution')
            ->count();

        // Retours du jour
        $todayReturns = UvTransaction::whereDate('transaction_date', today())
            ->where('transaction_type', 'retour')
            ->sum('amount');

        // Statistiques de la semaine
        $weekAmount = UvTransaction::whereBetween('transaction_date', [now()->startOfWeek(), now()->endOfWeek()])
            ->where('transaction_type', 'distribution')
            ->sum('amount');

        // Statistiques du mois
        $monthAmount = UvTransaction::whereMonth('transaction_date', now()->month)
            ->whereYear('transaction_date', now()->year)
            ->where('transaction_type', 'distribution')
            ->sum('amount');
        $monthCount = UvTransaction::whereMonth('transaction_date', now()->month)
            ->whereYear('transaction_date', now()->year)
            ->where('transaction_type', 'distribution')
            ->count();

        // Dette totale des agents
        $totalDebt = Agent::where('status', 'active')->sum('current_debt');

        // Tendance semaine
        $lastWeekAmount = UvTransaction::whereBetween('transaction_date', [
            now()->subWeek()->startOfWeek(),
            now()->subWeek()->endOfWeek(),
        ])->where('transaction_type', 'distribution')->sum('amount');

        $weekTrend = $lastWeekAmount > 0
            ? round((($weekAmount - $lastWeekAmount) / $lastWeekAmount) * 100, 1)
            : ($weekAmount > 0 ? 100 : 0);

        return [
            Stat::make('💳 Solde UV Disponible', number_format($soldeDisponible, 0, ',', ' ').' FCFA')
                ->description('Solde Master actuel')
                ->descriptionIcon('heroicon-m-wallet')
                ->color($soldeDisponible > 1000000 ? 'success' : 'warning'),

            Stat::make('📤 Ventes Aujourd\'hui', number_format($todayAmount, 0, ',', ' ').' FCFA')
                ->description($todayCount.' transaction(s)'.($todayReturns > 0 ? ' • Retours: '.number_format($todayReturns, 0, ',', ' ') : ''))
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color('primary'),

            Stat::make('📅 Cette Semaine', number_format($weekAmount, 0, ',', ' ').' FCFA')
                ->description(($weekTrend >= 0 ? '+' : '').$weekTrend.'% vs semaine dernière')
                ->descriptionIcon($weekTrend >= 0 ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
                ->color($weekTrend >= 0 ? 'success' : 'danger'),

            Stat::make('💰 Dette Agents', number_format($totalDebt, 0, ',', ' ').' FCFA')
                ->description(Agent::where('status', 'active')->where('current_debt', '>', 0)->count().' agent(s) avec dette')
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color($totalDebt > 0 ? 'danger' : 'success'),
        ];
    }
}
