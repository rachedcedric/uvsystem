<?php

namespace App\Filament\Resources\ApprovisionnementResource\Widgets;

use App\Models\Approvisionnement;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ApprovisionnementStatsWidget extends BaseWidget
{
    protected function getStats(): array
    {
        // Aujourd'hui
        $today = Approvisionnement::whereDate('date_approvisionnement', today())
            ->sum('amount');
        $todayCount = Approvisionnement::whereDate('date_approvisionnement', today())
            ->count();

        // Cette semaine
        $thisWeek = Approvisionnement::whereBetween('date_approvisionnement', [
            now()->startOfWeek(),
            now()->endOfWeek(),
        ])->sum('amount');
        $thisWeekCount = Approvisionnement::whereBetween('date_approvisionnement', [
            now()->startOfWeek(),
            now()->endOfWeek(),
        ])->count();

        // Semaine précédente (pour comparaison)
        $lastWeek = Approvisionnement::whereBetween('date_approvisionnement', [
            now()->subWeek()->startOfWeek(),
            now()->subWeek()->endOfWeek(),
        ])->sum('amount');
        $weekTrend = $lastWeek > 0 ? (($thisWeek - $lastWeek) / $lastWeek) * 100 : ($thisWeek > 0 ? 100 : 0);

        // Ce mois
        $thisMonth = Approvisionnement::whereMonth('date_approvisionnement', now()->month)
            ->whereYear('date_approvisionnement', now()->year)
            ->sum('amount');
        $thisMonthCount = Approvisionnement::whereMonth('date_approvisionnement', now()->month)
            ->whereYear('date_approvisionnement', now()->year)
            ->count();

        // Total cumulé
        $total = Approvisionnement::sum('amount');
        $totalCount = Approvisionnement::count();

        return [
            Stat::make('💵 Aujourd\'hui', number_format($today, 0, ',', ' ').' FCFA')
                ->description($todayCount.' approvisionnement(s)')
                ->descriptionIcon('heroicon-m-calendar')
                ->color('success'),

            Stat::make('📅 Cette semaine', number_format($thisWeek, 0, ',', ' ').' FCFA')
                ->description(
                    $thisWeekCount.' approvisionnement(s) • '.
                    ($weekTrend >= 0 ? '+' : '').number_format($weekTrend, 1).'% vs semaine dernière'
                )
                ->descriptionIcon($weekTrend >= 0 ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
                ->color($weekTrend >= 0 ? 'success' : 'danger'),

            Stat::make('📆 Ce mois', number_format($thisMonth, 0, ',', ' ').' FCFA')
                ->description($thisMonthCount.' approvisionnement(s)')
                ->descriptionIcon('heroicon-m-calendar-days')
                ->color('info'),

            Stat::make('📊 Total cumulé', number_format($total, 0, ',', ' ').' FCFA')
                ->description($totalCount.' approvisionnement(s) au total')
                ->descriptionIcon('heroicon-m-chart-bar')
                ->color('primary'),
        ];
    }
}
