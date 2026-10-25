<?php

namespace App\Filament\Widgets;

use App\Services\FournisseurDebtService;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Tuiles de la page Dettes Fournisseurs : cumul à crédit / déjà remboursé / dette restante / découverts (avances,
 * toujours affichés avec le signe « − »). Rendues comme un StatsOverviewWidget natif pour rester alignées avec les
 * autres widgets du projet. Aucun calcul propre : délègue entièrement à FournisseurDebtService.
 */
class FournisseurDebtStatsWidget extends BaseWidget
{
    protected static ?string $pollingInterval = '30s';

    protected function getColumns(): int
    {
        return 4;
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
                ->description('Remboursements non annulés')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('success'),

            Stat::make('Dette restante', $this->formatMoney($summary['resteAPayer']))
                ->description($summary['nbEndettes'].' fournisseur(s) à payer')
                ->icon('heroicon-o-exclamation-triangle')
                ->color($summary['resteAPayer'] > 0 ? 'danger' : 'gray'),

            Stat::make('Découverts fournisseurs', $this->formatMoney($summary['totalDecouvert']))
                ->description($summary['nbDecouverts'] > 0 ? $summary['nbDecouverts'].' avance(s) disponible(s)' : 'Aucune avance en cours')
                ->icon('heroicon-o-banknotes')
                ->color($summary['totalDecouvert'] < 0 ? 'success' : 'gray'),
        ];
    }

    private function formatMoney(float $amount): string
    {
        return number_format($amount, 0, ',', ' ').' FCFA';
    }
}
