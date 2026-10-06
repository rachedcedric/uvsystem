<?php

namespace App\Filament\Widgets;

use App\Models\Commission;
use App\Support\DailyBarChart;
use Carbon\Carbon;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;

/**
 * "Évolution des commissions — Mois en cours" : une commission (ligne
 * globale par jour métier) par jour du mois, 0 explicite pour les jours
 * sans commission clôturée (jamais un simple groupBy qui omettrait ces
 * jours). Même pattern que MonthlyChartWidget (CA), source unique
 * Commission::globales() (aucun recalcul).
 */
class CommissionMonthlyChartWidget extends ChartWidget
{
    protected static ?string $heading = '📊 Évolution des commissions — Mois en cours';

    protected static ?int $sort = 2;

    protected static ?string $pollingInterval = '60s';

    protected int|string|array $columnSpan = 'full';

    protected static ?string $maxHeight = '200px';

    protected function getData(): array
    {
        $debutMois = Carbon::now()->startOfMonth();
        $aujourdhui = Carbon::today();

        $rows = Commission::globales()
            ->forPeriod($debutMois, $aujourdhui)
            ->selectRaw('DATE(date_commission) as jour, SUM(commission_globale) as total')
            ->groupBy('jour')
            ->pluck('total', 'jour');

        $labels = [];
        $data = [];

        for ($date = $debutMois->copy(); $date->lte($aujourdhui); $date->addDay()) {
            $key = $date->toDateString();
            $labels[] = $date->format('d/m/Y');
            $data[] = (float) ($rows[$key] ?? 0);
        }

        $colors = DailyBarChart::colors($data, 'rgba(245, 158, 11, 0.72)', 'rgb(217, 119, 6)');

        return [
            'datasets' => [
                [
                    'label' => 'Commission (FCFA)',
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
