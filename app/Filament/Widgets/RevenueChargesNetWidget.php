<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\ChargesPage;
use App\Services\ChargeStatisticsService;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Total Revenu / Total Charges / Total Revenu net du dashboard (période
 * "Ce mois", cohérente avec le filtre par défaut de la page Gestion des
 * Charges). Aucun calcul propre : délègue entièrement à
 * ChargeStatisticsService, déjà source de vérité unique pour ces 3
 * montants (ChargesPage, ChargesExport) — jamais une deuxième formule.
 */
class RevenueChargesNetWidget extends BaseWidget
{
    protected static ?int $sort = 2;

    protected static ?string $pollingInterval = '30s';

    protected function getColumns(): int
    {
        return 3;
    }

    protected function getStats(): array
    {
        $summary = app(ChargeStatisticsService::class)->summary(ChargeStatisticsService::PERIOD_THIS_MONTH);
        $chargesUrl = ChargesPage::getUrl();

        return [
            Stat::make('Total Revenu', $this->formatMoney($summary['revenue']))
                ->description($summary['period_label'])
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color('success')
                ->icon('heroicon-o-banknotes')
                ->url($chargesUrl),

            Stat::make('Total Charges', $this->formatMoney($summary['charges']))
                ->description($summary['period_label'])
                ->descriptionIcon('heroicon-m-minus-circle')
                ->color('danger')
                ->icon('heroicon-o-receipt-percent')
                ->url($chargesUrl),

            Stat::make('Total Revenu net', $this->formatMoney($summary['net']))
                ->description($summary['period_label'])
                ->descriptionIcon($summary['net'] >= 0 ? 'heroicon-m-check-circle' : 'heroicon-m-exclamation-triangle')
                ->color($summary['net'] >= 0 ? 'success' : 'danger')
                ->icon('heroicon-o-scale')
                ->url($chargesUrl),
        ];
    }

    private function formatMoney(float $amount): string
    {
        return number_format($amount, 0, ',', ' ').' FCFA';
    }
}
