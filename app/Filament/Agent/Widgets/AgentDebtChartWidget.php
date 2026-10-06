<?php

namespace App\Filament\Agent\Widgets;

use App\Models\Agent;
use App\Models\Remboursement;
use App\Models\UvTransaction;
use App\Support\DailyBarChart;
use Filament\Support\RawJs;
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

        $labels = $dates->map(fn ($date) => \Carbon\Carbon::parse($date)->format('d/m/Y'))->toArray();
        $colors = DailyBarChart::colors($debtData, 'rgba(59, 130, 246, 0.68)', 'rgb(37, 99, 235)', false);

        return [
            'datasets' => [
                [
                    'label' => 'Dette (FCFA)',
                    'data' => $debtData,
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

        return $data['datasets'] === []
            ? null
            : DailyBarChart::indicators($data['labels'], $data['datasets'][0]['data'], false);
    }

    protected function getOptions(): RawJs
    {
        return DailyBarChart::options();
    }
}
