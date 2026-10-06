<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\DettesPage;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * 4 tuiles globales de la page Dettes (liste), rendues comme un
 * StatsOverviewWidget natif pour rester visuellement alignées avec les
 * autres widgets du projet (Dashboard, Ventes UV, Commissions) plutôt
 * qu'avec une mise en page Blade maison. Aucun calcul propre : délègue
 * entièrement à DettesPage::getSummary() (même source que le tableau et le
 * badge de menu).
 */
class DettesGlobalStatsWidget extends BaseWidget
{
    protected static ?string $pollingInterval = '30s';

    protected function getColumns(): int
    {
        return 4;
    }

    protected function getStats(): array
    {
        $summary = DettesPage::getSummary();

        return [
            Stat::make('Agents endettés', (string) $summary['count'])
                ->icon('heroicon-o-user-group')
                ->color('danger'),

            Stat::make('Dettes du jour', $this->formatMoney($summary['debtToday']))
                ->icon('heroicon-o-banknotes')
                ->color('primary'),

            Stat::make('Remboursements du jour', $this->formatMoney($summary['repaidToday']))
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('success'),

            Stat::make('Dette non remboursée', $this->formatMoney($summary['outstanding']))
                ->icon('heroicon-o-exclamation-triangle')
                ->color('warning'),
        ];
    }

    private function formatMoney(float $amount): string
    {
        return number_format($amount, 0, ',', ' ').' FCFA';
    }
}
