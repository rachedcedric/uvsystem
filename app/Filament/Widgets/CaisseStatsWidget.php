<?php

namespace App\Filament\Widgets;

use App\Services\CaissePeriodResolver;
use App\Services\CaisseService;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * "Solde aujourd'hui" / "Solde hier" : journées de caisse 08h30 → 08h29:59.
 * Aucun calcul propre : tout vient de CaisseService.
 */
class CaisseStatsWidget extends BaseWidget
{
    protected static ?string $pollingInterval = '30s';

    protected function getColumns(): int
    {
        return 2;
    }

    protected function getStats(): array
    {
        $service = app(CaisseService::class);
        $periods = app(CaissePeriodResolver::class);

        $hier = $periods->yesterdayDate();
        $today = $service->soldeAujourdhui();

        return [
            Stat::make("Solde aujourd'hui", CaisseService::fcfa($today))
                ->description('Journée de caisse : 08h30 à 08h29')
                ->icon('heroicon-o-wallet')
                ->color(bccomp($today, '0', 2) >= 0 ? 'success' : 'danger'),

            Stat::make('Solde hier', CaisseService::fcfa($service->soldeHier()))
                ->description($hier->translatedFormat('j F Y'))
                ->icon('heroicon-o-calendar-days')
                ->color('gray'),
        ];
    }
}
