<?php

namespace App\Filament\Widgets;

use App\Models\UvTransaction;
use App\Support\DailyBarChart;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\DB;

class TopAgentsWidget extends ChartWidget
{
    protected static ?string $heading = 'Top 5 agents — CA du mois';

    protected int|string|array $columnSpan = ['default' => 'full', 'xl' => 1];

    private ?array $dataCache = null;

    protected static ?int $sort = 4;

    protected function getData(): array
    {
        return $this->dataCache ??= $this->computeData();
    }

    private function computeData(): array
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
        $colors = DailyBarChart::colors($data, 'rgba(59, 130, 246, 0.68)', 'rgb(37, 99, 235)');

        return [
            'datasets' => [
                [
                    'label' => 'CA Mensuel (FCFA)',
                    'data' => $data,
                    'backgroundColor' => $colors['background'],
                    'borderColor' => $colors['border'],
                    'borderWidth' => 1,
                    'borderRadius' => 4,
                    'borderSkipped' => false,
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
        return DailyBarChart::options(title: 'Agent', horizontal: true);
    }

    public function getDescription(): ?string
    {
        $data = $this->getData();

        return DailyBarChart::indicators($data['labels'], $data['datasets'][0]['data']);
    }
}
