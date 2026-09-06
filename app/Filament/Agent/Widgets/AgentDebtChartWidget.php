<?php

namespace App\Filament\Agent\Widgets;

use App\Models\Agent;
use App\Models\Remboursement;
use App\Models\UvTransaction;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\Auth;

class AgentDebtChartWidget extends ChartWidget
{
    protected static ?string $heading = 'Évolution de la dette (30 derniers jours)';

    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected function getData(): array
    {
        $user = Auth::user();
        $agent = Agent::where('user_id', $user->id)->first();

        if (! $agent || ! $agent->id) {
            return [
                'datasets' => [],
                'labels' => [],
            ];
        }

        // Générer les 30 derniers jours
        $dates = collect();
        for ($i = 29; $i >= 0; $i--) {
            $dates->push(now()->subDays($i)->format('Y-m-d'));
        }

        // Calculer la dette cumulée pour chaque jour
        $debtData = [];
        $cumulativeDebt = 0;

        foreach ($dates as $date) {
            // Transactions du jour (augmente la dette)
            $dailyTransactions = UvTransaction::where('agent_id', $agent->id)
                ->whereDate('transaction_date', '<=', $date)
                ->sum('amount');

            // Remboursements jusqu'à ce jour (diminue la dette)
            $dailyRemboursements = Remboursement::where('agent_id', $agent->id)
                ->whereDate('payment_date', '<=', $date)
                ->sum('amount');

            $cumulativeDebt = $dailyTransactions - $dailyRemboursements;
            $debtData[] = round($cumulativeDebt, 2);
        }

        return [
            'datasets' => [
                [
                    'label' => 'Dette (FCFA)',
                    'data' => $debtData,
                    'backgroundColor' => 'rgba(239, 68, 68, 0.1)',
                    'borderColor' => 'rgb(239, 68, 68)',
                    'borderWidth' => 2,
                    'fill' => true,
                    'tension' => 0.4,
                ],
            ],
            'labels' => $dates->map(fn ($date) => \Carbon\Carbon::parse($date)->format('d/m'))->toArray(),
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
                ],
            ],
            'scales' => [
                'y' => [
                    'beginAtZero' => true,
                    'ticks' => [
                        'callback' => 'function(value) { return value.toLocaleString() + " FCFA"; }',
                    ],
                ],
            ],
        ];
    }
}
