<?php

namespace App\Filament\Widgets;

use App\Services\AnalyticsService;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class AdvancedKpisWidget extends BaseWidget
{
    protected static ?int $sort = 4;

    protected static ?string $pollingInterval = '30s';

    protected function getStats(): array
    {
        $analytics = app(AnalyticsService::class);
        $kpis = $analytics->getDashboardKpis('month');
        $prediction = $analytics->predictCashFlow(30);

        // Tendance CA
        $caIcon = $kpis['chiffre_affaires']['trend'] === 'up'
            ? 'heroicon-m-arrow-trending-up'
            : 'heroicon-m-arrow-trending-down';
        $caColor = $kpis['chiffre_affaires']['growth'] >= 0 ? 'success' : 'danger';

        // Prédiction
        $predTrend = match ($prediction['trend']) {
            'up' => '📈 Hausse',
            'down' => '📉 Baisse',
            default => '➡️ Stable',
        };

        // Tendance dette
        $detteValue = $kpis['dette_totale']['value'] ?? 0;
        $detteColor = $detteValue > 0 ? 'warning' : 'success';

        return [
            Stat::make('CA Mensuel', number_format($kpis['chiffre_affaires']['value'], 0, ',', ' ').' FCFA')
                ->description(($kpis['chiffre_affaires']['growth'] >= 0 ? '+' : '').$kpis['chiffre_affaires']['growth'].'% vs mois précédent')
                ->descriptionIcon($caIcon)
                ->color($caColor),

            Stat::make('Transactions', $kpis['transactions']['count'] ?? 0)
                ->description('Moy: '.number_format($kpis['transactions']['avg_amount'] ?? 0, 0, ',', ' ').' FCFA')
                ->descriptionIcon('heroicon-m-receipt-percent')
                ->color('primary'),

            Stat::make('Dette Totale', number_format($detteValue, 0, ',', ' ').' FCFA')
                ->description(($kpis['dette_totale']['agents_count'] ?? 0).' agents endettés')
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color($detteColor),

            Stat::make('Prévision 30j', number_format($prediction['total_predicted'], 0, ',', ' ').' FCFA')
                ->description("{$predTrend} | Confiance: {$prediction['confidence']}%")
                ->descriptionIcon('heroicon-m-chart-bar')
                ->color('info'),
        ];
    }
}
