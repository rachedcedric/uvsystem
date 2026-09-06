<?php

namespace App\Filament\Widgets;

use App\Models\UvTransaction;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\DB;

class TopAgentsWidget extends ChartWidget
{
    protected static ?string $heading = 'Top 5 Agents (CA du mois)';

    protected static ?int $sort = 10;

    protected function getData(): array
    {
        $topAgents = UvTransaction::query()
            ->select('agent_id', DB::raw('SUM(amount) as total_ca'))
            ->where('transaction_type', 'distribution')
            ->whereMonth('transaction_date', now()->month)
            ->whereYear('transaction_date', now()->year)
            ->groupBy('agent_id')
            ->orderByDesc('total_ca')
            ->limit(5)
            ->with('agent')
            ->get();

        $labels = $topAgents->map(fn ($t) => $t->agent?->name ?? 'Inconnu')->toArray();
        $data = $topAgents->pluck('total_ca')->map(fn ($v) => (float) $v)->toArray();

        return [
            'datasets' => [
                [
                    'label' => 'CA Mensuel (FCFA)',
                    'data' => $data,
                    'backgroundColor' => [
                        'rgba(34, 197, 94, 0.7)',
                        'rgba(59, 130, 246, 0.7)',
                        'rgba(249, 115, 22, 0.7)',
                        'rgba(168, 85, 247, 0.7)',
                        'rgba(236, 72, 153, 0.7)',
                    ],
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getOptions(): array
    {
        return [
            'indexAxis' => 'y', // Horizontal bars
            'plugins' => [
                'legend' => [
                    'display' => false,
                ],
            ],
        ];
    }
}
