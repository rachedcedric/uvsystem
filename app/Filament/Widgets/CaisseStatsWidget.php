<?php

namespace App\Filament\Widgets;

use App\Services\CaisseService;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * 3 tuiles de "Ma Caisse" : Solde actuel / Total entrées / Total sorties.
 * Aucun calcul propre : délègue entièrement à CaisseService.
 */
class CaisseStatsWidget extends BaseWidget
{
    protected static ?string $pollingInterval = '30s';

    protected function getColumns(): int
    {
        return 3;
    }

    protected function getStats(): array
    {
        $totals = app(CaisseService::class)->totals();

        return [
            Stat::make('Solde actuel', $this->formatMoney($totals['solde']))
                ->icon('heroicon-o-wallet')
                ->color($totals['solde'] >= 0 ? 'success' : 'danger'),

            Stat::make('Total entrées', $this->formatMoney($totals['entrees']))
                ->icon('heroicon-o-arrow-trending-up')
                ->color('success'),

            Stat::make('Total sorties', $this->formatMoney($totals['sorties']))
                ->icon('heroicon-o-arrow-trending-down')
                ->color('danger'),
        ];
    }

    private function formatMoney(float $amount): string
    {
        return number_format($amount, 0, ',', ' ').' FCFA';
    }
}
