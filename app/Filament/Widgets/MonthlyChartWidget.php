<?php

namespace App\Filament\Widgets;

use App\Models\UvTransaction;
use App\Support\DailyBarChart;
use Carbon\Carbon;
use Filament\Support\RawJs;
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
            $labels[] = $date->format('d/m/Y');
            $data[] = (float) ($rows[$key] ?? 0);
        }

        $colors = DailyBarChart::colors($data, 'rgba(59, 130, 246, 0.68)', 'rgb(37, 99, 235)');

        return [
            'datasets' => [
                [
                    'label' => 'CA Journalier (FCFA)',
                    'data' => $data,
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

    public function getDescription(): ?string
    {
        $data = $this->getData();

        return DailyBarChart::indicators($data['labels'], $data['datasets'][0]['data']);
    }

    protected function getOptions(): RawJs
    {
        return DailyBarChart::options();
    }
}
