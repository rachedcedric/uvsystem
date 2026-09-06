<?php

namespace App\Filament\Widgets;

use App\Models\UvTransaction;
use Carbon\Carbon;
use Filament\Widgets\ChartWidget;

class MonthlyChartWidget extends ChartWidget
{
    protected static ?string $heading = '📈 Évolution du CA — Mois en cours';

    protected static ?int $sort = 5;

    protected static ?string $pollingInterval = '60s';

    protected int|string|array $columnSpan = 'full';

    protected static ?string $maxHeight = '280px';

    protected function getData(): array
    {
        $debutMois = Carbon::now()->startOfMonth();
        $aujourdhui = Carbon::today();

        $rows = UvTransaction::where('transaction_type', 'distribution')
            ->whereBetween('transaction_date', [$debutMois, Carbon::now()])
            ->selectRaw('DATE(transaction_date) as jour, SUM(amount) as total')
            ->groupBy('jour')
            ->pluck('total', 'jour');

        $labels = [];
        $data = [];

        for ($date = $debutMois->copy(); $date->lte($aujourdhui); $date->addDay()) {
            $key = $date->toDateString();
            $labels[] = $date->format('d/m');
            $data[] = (float) ($rows[$key] ?? 0);
        }

        return [
            'datasets' => [
                [
                    'label' => 'CA Journalier (FCFA)',
                    'data' => $data,
                    'backgroundColor' => 'rgba(34, 197, 94, 0.15)',
                    'borderColor' => 'rgb(34, 197, 94)',
                    'borderWidth' => 2,
                    'fill' => true,
                    'tension' => 0.35,
                    'pointRadius' => 3,
                    'pointBackgroundColor' => 'rgb(34, 197, 94)',
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
            'maintainAspectRatio' => false,
            'plugins' => [
                'legend' => [
                    'display' => false,
                ],
                'tooltip' => [
                    'callbacks' => [
                        'label' => "function(context) { return context.dataset.label + ': ' + new Intl.NumberFormat('fr-FR').format(context.raw) + ' FCFA'; }",
                    ],
                ],
            ],
            'scales' => [
                'y' => [
                    'beginAtZero' => true,
                    'ticks' => [
                        'callback' => "function(value) { return new Intl.NumberFormat('fr-FR', { notation: 'compact' }).format(value) + ' F'; }",
                    ],
                ],
            ],
        ];
    }
}
