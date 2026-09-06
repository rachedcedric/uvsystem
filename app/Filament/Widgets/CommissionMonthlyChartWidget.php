<?php

namespace App\Filament\Widgets;

use App\Models\Commission;
use Carbon\Carbon;
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

    protected static ?string $maxHeight = '260px';

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
            $labels[] = $date->format('d/m');
            $data[] = (float) ($rows[$key] ?? 0);
        }

        return [
            'datasets' => [
                [
                    'label' => 'Commission (FCFA)',
                    'data' => $data,
                    'backgroundColor' => 'rgba(245, 158, 11, 0.15)',
                    'borderColor' => 'rgb(245, 158, 11)',
                    'borderWidth' => 2,
                    'fill' => true,
                    'tension' => 0.35,
                    'pointRadius' => 3,
                    'pointBackgroundColor' => 'rgb(245, 158, 11)',
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
            // mode 'index' + intersect:false : le tooltip apparaît dès que le
            // curseur/le doigt est proche d'un point sur l'axe X, sans viser
            // le pixel exact — essentiel pour un tap fiable sur mobile.
            'interaction' => [
                'mode' => 'index',
                'intersect' => false,
            ],
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
