<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\AgentDebtDetailPage;
use App\Models\Agent;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * 4 tuiles de la page détail dette d'un agent (Dette du jour / Total dette /
 * Remboursé / Reste à payer), rendues comme un StatsOverviewWidget natif
 * pour rester visuellement alignées avec les autres widgets du projet.
 * Aucun calcul propre : délègue à AgentDebtDetailPage::summaryFor().
 */
class AgentDebtSummaryWidget extends BaseWidget
{
    public Agent $agent;

    protected static ?string $pollingInterval = '30s';

    public function mount(Agent $agent): void
    {
        $this->agent = $agent;
    }

    protected function getColumns(): int
    {
        return 4;
    }

    protected function getStats(): array
    {
        $summary = AgentDebtDetailPage::summaryFor($this->agent);

        return [
            Stat::make('Dette du jour', $this->formatMoney($summary['detteDuJour']))
                ->icon('heroicon-o-calendar')
                ->color('warning'),

            Stat::make('Total dette', $this->formatMoney($summary['totalDette']))
                ->icon('heroicon-o-banknotes')
                ->color('primary'),

            Stat::make('Remboursé', $this->formatMoney($summary['rembourse']))
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('success'),

            Stat::make('Reste à payer', $this->formatMoney($summary['resteAPayer']))
                ->icon('heroicon-o-exclamation-triangle')
                ->color($summary['resteAPayer'] > 0 ? 'danger' : 'success'),
        ];
    }

    private function formatMoney(float $amount): string
    {
        return number_format($amount, 0, ',', ' ').' FCFA';
    }
}
