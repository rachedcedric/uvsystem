<?php

namespace App\Filament\Widgets;

use App\Services\AnalyticsService;
use Filament\Widgets\ChartWidget;

class CashFlowPredictionWidget extends ChartWidget
{
    protected static ?string $heading = '📈 Prédiction Cash-Flow (30 jours)';

    protected static ?int $sort = 12;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $maxHeight = '300px';

    protected function getData(): array
    {
        $analytics = app(AnalyticsService::class);
        $prediction = $analytics->predictCashFlow(30);

        if (empty($prediction['predictions'])) {
            return [
                'datasets' => [],
                'labels' => [],
            ];
        }

        $labels = [];
        $predicted = [];
        $lower = [];
        $upper = [];

        foreach ($prediction['predictions'] as $day) {
            $labels[] = $day['day'];
            $predicted[] = $day['predicted'];
            $lower[] = $day['lower'];
            $upper[] = $day['upper'];
        }

        return [
            'datasets' => [
                [
                    'label' => 'Prédit',
                    'data' => $predicted,
                    'borderColor' => '#3b82f6',
                    'backgroundColor' => 'rgba(59, 130, 246, 0.1)',
                    'fill' => true,
                    'tension' => 0.3,
                ],
                [
                    'label' => 'Min',
                    'data' => $lower,
                    'borderColor' => '#ef4444',
                    'borderDash' => [5, 5],
                    'fill' => false,
                    'tension' => 0.3,
                ],
                [
                    'label' => 'Max',
                    'data' => $upper,
                    'borderColor' => '#22c55e',
                    'borderDash' => [5, 5],
                    'fill' => false,
                    'tension' => 0.3,
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => [
                'legend' => [
                    'display' => true,
                    'position' => 'top',
                ],
                'tooltip' => [
                    'callbacks' => [
                        'label' => "function(context) {
                            return context.dataset.label + ': ' + new Intl.NumberFormat('fr-FR').format(context.raw) + ' FCFA';
                        }",
                    ],
                ],
            ],
            'scales' => [
                'y' => [
                    'beginAtZero' => true,
                    'ticks' => [
                        'callback' => "function(value) {
                            return new Intl.NumberFormat('fr-FR', { notation: 'compact' }).format(value) + ' FCFA';
                        }",
                    ],
                ],
            ],
        ];
    }

    public function getDescription(): ?string
    {
        $analytics = app(AnalyticsService::class);
        $prediction = $analytics->predictCashFlow(30);

        $trend = match ($prediction['trend']) {
            'up' => '📈 Tendance haussière',
            'down' => '📉 Tendance baissière',
            default => '➡️ Stable',
        };

        $total = number_format($prediction['total_predicted'], 0, ',', ' ');
        $confidence = $prediction['confidence'];

        return "{$trend} | Prévu: {$total} FCFA | Confiance: {$confidence}%";
    }
}
