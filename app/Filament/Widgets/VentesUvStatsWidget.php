<?php

namespace App\Filament\Widgets;

use App\Models\UvTransaction;
use App\Services\BusinessPeriodResolver;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;

/**
 * 4 widgets de la page Ventes UV : Aujourd'hui / Cette semaine / Ce mois /
 * Total cumulé — tous respectent le jour métier (cutoff 18h02) via
 * BusinessPeriodResolver, jamais un whereDate() calendaire en dur.
 */
class VentesUvStatsWidget extends BaseWidget
{
    protected static ?int $sort = 2;

    protected function getColumns(): int
    {
        return 4;
    }

    protected function getStats(): array
    {
        $resolver = app(BusinessPeriodResolver::class);

        $today = $resolver->today();
        $thisWeek = $resolver->thisWeek();
        $thisMonth = $resolver->thisMonth();

        $ventesAujourdhui = $this->sumVentes($today['start'], $today['end']);
        $ventesSemaine = $this->sumVentes($thisWeek['start'], $thisWeek['end']);
        $ventesMois = $this->sumVentes($thisMonth['start'], $thisMonth['end']);
        $ventesCumulees = (float) UvTransaction::where('transaction_type', 'distribution')
            ->where('status', '!=', 'cancelled')
            ->sum('amount');

        return [
            CompactStat::make('Ventes Aujourd\'hui', $this->formatMoney($ventesAujourdhui))
                ->description('Journée métier en cours')
                ->descriptionIcon('heroicon-m-calendar')
                ->icon('heroicon-o-shopping-cart')
                ->color('primary'),

            CompactStat::make('Cette semaine', $this->formatMoney($ventesSemaine))
                ->descriptionIcon('heroicon-m-calendar-days')
                ->icon('heroicon-o-shopping-cart')
                ->color('info'),

            CompactStat::make('Ce mois', $this->formatMoney($ventesMois))
                ->descriptionIcon('heroicon-m-calendar-days')
                ->icon('heroicon-o-shopping-cart')
                ->color('warning'),

            CompactStat::make('Total cumulé', $this->formatMoney($ventesCumulees))
                ->description('Depuis le début')
                ->descriptionIcon('heroicon-m-archive-box')
                ->icon('heroicon-o-banknotes')
                ->color('success'),
        ];
    }

    private function sumVentes(\Carbon\Carbon $start, \Carbon\Carbon $end): float
    {
        return (float) UvTransaction::where('transaction_type', 'distribution')
            ->where('status', '!=', 'cancelled')
            ->whereBetween('transaction_date', [$start, $end])
            ->sum('amount');
    }

    private function formatMoney(float $amount): string
    {
        return number_format($amount, 0, ',', ' ').' FCFA';
    }
}
