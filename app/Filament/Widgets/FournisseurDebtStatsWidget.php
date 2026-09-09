<?php

namespace App\Filament\Widgets;

use App\Services\FournisseurDebtService;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * 3 tuiles de la page Dettes Fournisseurs : Dette actuelle / Déjà
 * remboursée / Dette restante. Rendues comme un StatsOverviewWidget natif
 * pour rester visuellement alignées avec les autres widgets du projet.
 * Aucun calcul propre : délègue entièrement à FournisseurDebtService.
 */
class FournisseurDebtStatsWidget extends BaseWidget
{
    protected static ?string $pollingInterval = '30s';

    protected function getColumns(): int
    {
        return 3;
    }

    protected function getStats(): array
    {
        $summary = app(FournisseurDebtService::class)->globalSummary();

        return [
            Stat::make('Dette actuelle', $this->formatMoney($summary['totalDette']))
                ->description('Cumul des approvisionnements à crédit')
                ->icon('heroicon-o-truck')
                ->color('primary'),

            Stat::make('Déjà remboursée', $this->formatMoney($summary['totalRembourse']))
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('success'),

            Stat::make('Dette restante', $this->formatMoney($summary['resteAPayer']))
                ->icon('heroicon-o-exclamation-triangle')
                ->color($summary['resteAPayer'] > 0 ? 'danger' : 'success'),
        ];
    }

    private function formatMoney(float $amount): string
    {
        return number_format($amount, 0, ',', ' ').' FCFA';
    }
}
