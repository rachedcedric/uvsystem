<?php

namespace App\Filament\Widgets;

use App\Models\Remboursement;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class RemboursementsStatsWidget extends BaseWidget
{
    protected static ?int $sort = 3;

    protected function getStats(): array
    {
        // Statistiques du jour
        $todayAmount = Remboursement::whereDate('payment_date', today())->sum('amount');
        $todayCount = Remboursement::whereDate('payment_date', today())->count();

        // Statistiques de la semaine
        $weekAmount = Remboursement::whereBetween('payment_date', [now()->startOfWeek(), now()->endOfWeek()])->sum('amount');
        $weekCount = Remboursement::whereBetween('payment_date', [now()->startOfWeek(), now()->endOfWeek()])->count();

        // Statistiques du mois
        $monthAmount = Remboursement::whereMonth('payment_date', now()->month)
            ->whereYear('payment_date', now()->year)
            ->sum('amount');
        $monthCount = Remboursement::whereMonth('payment_date', now()->month)
            ->whereYear('payment_date', now()->year)
            ->count();

        // Total général
        $totalAmount = Remboursement::sum('amount');
        $totalCount = Remboursement::count();

        // Calcul tendance (comparaison avec période précédente)
        $lastWeekAmount = Remboursement::whereBetween('payment_date', [
            now()->subWeek()->startOfWeek(),
            now()->subWeek()->endOfWeek(),
        ])->sum('amount');

        $weekTrend = $lastWeekAmount > 0
            ? round((($weekAmount - $lastWeekAmount) / $lastWeekAmount) * 100, 1)
            : ($weekAmount > 0 ? 100 : 0);

        return [
            Stat::make('💰 Aujourd\'hui', number_format($todayAmount, 0, ',', ' ').' FCFA')
                ->description($todayCount.' remboursement(s)')
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color('success')
                ->chart([0, $todayAmount > 0 ? 1 : 0]),

            Stat::make('📅 Cette semaine', number_format($weekAmount, 0, ',', ' ').' FCFA')
                ->description($weekCount.' remboursement(s) • '.($weekTrend >= 0 ? '+' : '').$weekTrend.'%')
                ->descriptionIcon($weekTrend >= 0 ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
                ->color($weekTrend >= 0 ? 'success' : 'danger'),

            Stat::make('📆 Ce mois', number_format($monthAmount, 0, ',', ' ').' FCFA')
                ->description($monthCount.' remboursement(s)')
                ->descriptionIcon('heroicon-m-calendar')
                ->color('info'),

            Stat::make('📊 Total cumulé', number_format($totalAmount, 0, ',', ' ').' FCFA')
                ->description($totalCount.' remboursement(s) au total')
                ->descriptionIcon('heroicon-m-chart-bar')
                ->color('primary'),
        ];
    }
}
