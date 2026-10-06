<?php

namespace App\Filament\Widgets;

use App\Services\AnalyticsService;
use App\Support\DailyBarChart;
use Filament\Support\RawJs;
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
            $labels[] = \Carbon\Carbon::parse($day['date'])->format('d/m/Y');
            $predicted[] = $day['predicted'];
            $lower[] = $day['lower'];
            $upper[] = $day['upper'];
        }

        $colors = DailyBarChart::colors($predicted, 'rgba(59, 130, 246, 0.68)', 'rgb(37, 99, 235)');

        return [
            'datasets' => [
                [
                    'label' => 'Prédit',
                    'data' => $predicted,
                    'minimums' => $lower,
                    'maximums' => $upper,
                    'backgroundColor' => $colors['background'],
                    'borderColor' => $colors['border'],
                    'borderWidth' => 1,
                    'borderRadius' => 4,
                    'borderSkipped' => false,
                    'barPercentage' => 0.72,
                    'categoryPercentage' => 0.82,
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getOptions(): RawJs
    {
        return DailyBarChart::options(showPredictionRange: true);
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

        $data = $this->getData();
        $indicators = $data['datasets'] === []
            ? null
            : DailyBarChart::indicators($data['labels'], $data['datasets'][0]['data']);

        return "{$trend} | Prévu: {$total} FCFA | Confiance: {$confidence}%".($indicators ? " | {$indicators}" : '');
    }
}
